<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Assistant\AssistantUnavailable;
use App\Assistant\DocChunk;
use App\Assistant\DocChunker;
use App\Assistant\MethodologyRetriever;
use App\Assistant\OllamaEmbeddingClient;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Builds the methodology doc-RAG index for the assistant (Phase 2): chunks the project's own
 * `docs/`, embeds each chunk on the LOCAL model, and writes the vectors to a gitignored JSON index
 * the assistant loads at query time. No silent failure — it reports how many files and chunks it
 * indexed, and exits non-zero if the local runtime is unreachable rather than writing an empty index.
 *
 * Hard rule: the real household's PII lives in `*.local.md` docs — those are NEVER indexed, so a
 * methodology answer can never surface the couple's private data. The exclusion is by filename here
 * (belt) and the files are gitignored (braces).
 */
class BuildAssistantDocIndex extends Command
{
    protected $signature = 'assistant:index-docs
        {--model= : Override the embedding model (defaults to config assistant.embed_model)}';

    protected $description = 'Embed the docs/ methodology corpus into the local assistant\'s doc-RAG index.';

    /** Chunks embedded per request to the local model. */
    private const BATCH = 16;

    public function handle(): int
    {
        $docsPath = (string) config('assistant.docs_path');
        $indexPath = (string) config('assistant.doc_index_path');
        $model = (string) ($this->option('model') ?: config('assistant.embed_model'));

        if (! is_dir($docsPath)) {
            $this->error("Docs directory not found: {$docsPath}");

            return self::FAILURE;
        }

        $client = new OllamaEmbeddingClient(
            (string) config('assistant.base_url'),
            $model,
            (int) config('assistant.timeout'),
            (int) config('assistant.probe_timeout'),
        );

        if (! $client->isAvailable()) {
            $this->error('The local model runtime (Ollama) is not reachable at '.config('assistant.base_url').'. Start it and retry.');

            return self::FAILURE;
        }

        $files = $this->methodologyFiles($docsPath);
        if ($files === []) {
            $this->warn("No methodology docs to index in {$docsPath} (after excluding *.local.md).");

            return self::FAILURE;
        }

        // 1. Chunk every doc (pure, fast).
        /** @var list<DocChunk> $chunks */
        $chunks = [];
        $perFile = [];
        foreach ($files as $file) {
            $name = basename($file);
            $fileChunks = DocChunker::chunk($name, (string) file_get_contents($file));
            $perFile[$name] = count($fileChunks);
            $chunks = [...$chunks, ...$fileChunks];
        }

        if ($chunks === []) {
            $this->warn('The docs produced no chunks to index.');

            return self::FAILURE;
        }

        // 2. Embed them in batches on the local model (the slow part — show progress).
        $this->info('Embedding '.count($chunks).' chunks from '.count($files)." docs on {$model}…");
        $bar = $this->output->createProgressBar(count($chunks));
        $bar->start();

        $embedded = [];
        $dimensions = 0;
        foreach (array_chunk($chunks, self::BATCH) as $batch) {
            $inputs = array_map(
                static fn (DocChunk $c): string => MethodologyRetriever::DOCUMENT_PREFIX.$c->embedText(),
                $batch,
            );

            try {
                $vectors = $client->embedBatch($inputs);
            } catch (AssistantUnavailable $e) {
                $bar->finish();
                $this->newLine(2);
                $this->error('Embedding failed partway: '.$e->getMessage());

                return self::FAILURE;
            }

            foreach ($batch as $i => $chunk) {
                $embedded[] = $chunk->withEmbedding($vectors[$i]);
                $dimensions = $dimensions ?: count($vectors[$i]);
            }
            $bar->advance(count($batch));
        }
        $bar->finish();
        $this->newLine(2);

        // 3. Write the index (gitignored build artifact).
        File::ensureDirectoryExists(dirname($indexPath));
        $payload = [
            'version' => 1,
            'model' => $model,
            'dimensions' => $dimensions,
            'built_at' => (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM),
            'sources' => $perFile,
            'chunks' => array_map(static fn (DocChunk $c): array => $c->toArray(), $embedded),
        ];
        File::put($indexPath, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->table(
            ['Doc', 'Chunks'],
            array_map(static fn (string $name, int $n): array => [$name, $n], array_keys($perFile), array_values($perFile)),
        );
        $this->info('Indexed '.count($embedded)." chunks ({$dimensions}-dim) → {$indexPath}");

        return self::SUCCESS;
    }

    /**
     * The `.md` docs to index: the curated methodology allow-list (config `assistant.methodology_docs`)
     * when set, else every markdown doc in the corpus. Either way the `*.local.md` PII files (the real
     * household's data) are excluded — they must never enter the index. A named-but-missing doc is
     * reported, not silently skipped.
     *
     * @return list<string> absolute file paths, sorted
     */
    private function methodologyFiles(string $docsPath): array
    {
        $base = rtrim($docsPath, '/\\').DIRECTORY_SEPARATOR;

        /** @var list<string> $allow */
        $allow = array_values((array) config('assistant.methodology_docs', []));

        if ($allow !== []) {
            $files = [];
            foreach ($allow as $name) {
                $path = $base.$name;
                if (is_file($path)) {
                    $files[] = $path;
                } else {
                    $this->warn("Configured methodology doc not found (skipped): {$name}");
                }
            }
        } else {
            $files = glob($base.'*.md') ?: [];
        }

        $files = array_filter($files, static fn (string $f): bool => ! str_ends_with(strtolower($f), '.local.md'));
        sort($files);

        return array_values($files);
    }
}
