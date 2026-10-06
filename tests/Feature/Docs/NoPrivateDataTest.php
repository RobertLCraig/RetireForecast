<?php

namespace Tests\Feature\Docs;

use Tests\TestCase;

/**
 * Private-data guard for tracked files, shaped like HandoverHygieneTest. The real couple's
 * data lives only in the gitignored `docs/*.local.md` captures; a lender, broker, adviser,
 * town, address or personal figure copied out of them into a tracked doc or test fails here.
 *
 * The denylist is held as sha256 hashes so this file does not leak what it guards. Each term
 * is lower-case, at most three words, separated by single spaces; a date or a postcode is
 * written as its words ("2000 01 31"). To add one, append
 * `php -r "echo hash('sha256', 'the term');"` to DENYLIST.
 *
 * ponytail: plain hashes stop a reader, not an attacker with a gazetteer; a short town name is
 * recoverable by dictionary. Good enough while the repository is private; card 0012 owns
 * whether anything more is needed before a public release.
 */
final class NoPrivateDataTest extends TestCase
{
    private const DENYLIST = [
        'a0caf5b7aa02e3f6a234c4c46ff042eb127f1a6eaa7f78a6a1bf8658b88aeff8',
        'd2d32ef3eb916674051858f01168c3e57e3475494b8218cad71ce3b6f478f703',
        'b904821d09900be534f1bb938f6fbd2dea7da91a49bcf26d743751d88e1daca1',
        '99d945770082e9bd9e7312be9a914e623f904cbe22f00bd88f9689a9ca996a43',
        '96e2f100589cffc5d02c3ace4b9b8fe37bb37ba5150081d1d1db62c0d788827c',
        'be72fb3b5f85b46efaa4995b3f05141dbf3893865058e7a40d763b0b487b09c6',
        '418fc1cd096213da8fece81028f7f70b0fcbf74d2a85844c4483ca760c2aaa7a',
        '30581bb3a5385ee6fd6e2caec8843212081d35c2d24a48c026f39751e3c1ce10',
        'daef7b1c9bf3a3636565192516a9fbc764f9eaf7eaadab1643eee0e14b397a2a',
        '98ce936d79d2b1f61bc924e821bedbb2434d7a93eee1db3816443464926fe435',
        '13d8f36ec63a0d32df2f436ec5f2bd000f4a4435a644e9a8cd2b59977c732b9b',
        '600e272ee0214b3159b35710ff33b8a462a737f6f18abefba8ea4df9bc0912dd',
        'ebdffaa4c64dd1ed6ff82b86931515730c107dfce34ddc3b2ae112a2b62cc923',
        '226b40857e000191b6395ed6e9b63d73b54e79dbf589c3951870d6bdc0c4ec06',
        '020ea7d90389f511807122dcd5de39bd96337ed33732d5d1581b7ed42985dbeb',
        'bca2dbb4315460a72ae73ebbd659697a3c971752c3c32fe5de569a3a97cdf05e',
        '5379cb825321d4e106e5fa6696c9ec53289916ba7e3874c67b9a0746a7a110a4',
        '9fd3974b4aa11f43a6932820ff7811b6254f2ed882dd812152339a15b48af14e',
        'ad50371d60e2fdc9c2a5d6d15832938f089333738cae227b5fa5edad64198337',
        'c6bb98f8034d780019b548a395f77c778b2c5c9dba6fba9eb05ce1ce9b5838de',
        '8822eac3400e76f35569ecbedd2324f84eb2626a3e7dafccfd6966e0730ca008',
        '0fb95d7d2a637133554be00b3a38969f859f7342fe6869ed1f1208d175af84ea',
        '481992153871d438c30cc532a9ccf32a2ebacd90978272f81d4e58d825f466f8',
        '99f4474cea84ecfcc2f8de0323767fc2e462a0f1f2647e65e83ab2ac7e3bbc29',
        'ef186a18171c327aa7ce34d5b865e4c38c87938c40298370d3aefee521417eea',
        '7d6af266d49151c9badcdcbbe482d169685da9ab5aab492b28c94456199617a7',
        'e87e6f9e2dd06835a035868f02cd53dff6c6e1bca293627036ddc10413cc5698',
        'f488392feafaa7d1b9a1187c10535782772355d41bb00bc94660c2bfaa2b4886',
        '6dc85af0d3606192d97ae654e352bcfe721518ddce05dbf047ce418dac9e606e',
        // A canary, so the matcher itself is proved by test_the_matcher_finds_a_term_wrapped_over_a_line.
        '9b4c9f653afd952ed38572ac4b1c9c9c7772d903e572b1f77b9c5f4fce868919',
    ];

    /**
     * Cards this card may not edit, which still carry denied terms, keyed by card-number prefix
     * (a lane move renames the folder, not the file). The value is the card that scrubs them.
     * An entry that no longer finds anything fails, so the exemption cannot outlive the leak.
     */
    private const PENDING_SCRUB = [
        '0022-' => '0174',
        '0023-' => '0174',
    ];

    /** @return list<array{line: int, term: string}> every denied term in $text */
    private function hits(string $text): array
    {
        $denied = array_flip(self::DENYLIST);
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N},.]*/u', mb_strtolower($text), $m, PREG_OFFSET_CAPTURE);
        $tokens = [];
        foreach ($m[0] as [$word, $offset]) {
            $tokens[] = [rtrim($word, ',.'), substr_count($text, "\n", 0, $offset) + 1];
        }

        $hits = [];
        foreach ($tokens as $i => [, $line]) {
            for ($n = 1; $n <= 3 && $i + $n <= count($tokens); $n++) {
                $term = implode(' ', array_column(array_slice($tokens, $i, $n), 0));
                if (isset($denied[hash('sha256', $term)])) {
                    $hits[] = ['line' => $line, 'term' => $term];
                }
            }
        }

        return $hits;
    }

    /** @return list<string> tracked paths under docs/ and tests/ */
    private function trackedFiles(): array
    {
        exec('git -C '.escapeshellarg(base_path()).' ls-files -- docs tests', $files, $status);
        $this->assertSame(0, $status, 'git ls-files failed, so the private-data guard could not see the tracked files.');
        $this->assertNotEmpty($files, 'git ls-files listed no tracked docs or tests.');

        return $files;
    }

    private function pendingCard(string $path): ?string
    {
        foreach (self::PENDING_SCRUB as $prefix => $card) {
            if (str_starts_with($path, 'docs/board/') && str_starts_with(basename($path), $prefix)) {
                return $card;
            }
        }

        return null;
    }

    public function test_no_tracked_doc_or_test_names_the_private_couple(): void
    {
        $leaks = [];
        $stale = [];
        foreach ($this->trackedFiles() as $path) {
            $hits = $this->hits((string) file_get_contents(base_path($path)));
            if ($this->pendingCard($path) !== null) {
                if ($hits === []) {
                    $stale[] = $path;
                }

                continue;
            }
            foreach ($hits as $hit) {
                $leaks[] = "{$path}:{$hit['line']}: '{$hit['term']}'";
            }
        }

        $this->assertSame([], $leaks,
            'Private data from the gitignored captures is in tracked files. Move it into docs/SCENARIO-V2.local.md '
            ."(or the capture it came from) and point at it by card number:\n".implode("\n", $leaks));
        $this->assertSame([], $stale,
            "These cards are exempt as pending a scrub but carry no denied term now; drop them from PENDING_SCRUB:\n"
            .implode("\n", $stale));
    }

    public function test_the_matcher_finds_a_term_wrapped_over_a_line(): void
    {
        // strrev keeps the canary out of this file as two adjacent words.
        $text = 'line one'."\n".'a Denylist'."\n".strrev('yranac').', wrapped';

        $this->assertSame([['line' => 2, 'term' => 'denylist '.strrev('yranac')]], $this->hits($text));
    }
}
