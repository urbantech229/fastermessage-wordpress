<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bin/chaines-traduisibles.php';

/**
 * TRADUCTION FRANÇAISE COMPLÈTE — un libellé ajouté dans `src/` sans sa
 * traduction fait échouer les tests, au lieu d'apparaître en anglais au milieu
 * d'un écran français.
 */
final class TranslationTest extends TestCase
{
    /** @return array<string, string> */
    private function french(): array
    {
        $file = require dirname(__DIR__) . '/languages/fastermessage-fr_FR.l10n.php';

        return $file['messages'];
    }

    public function testChaqueChaineASaTraductionFrancaise(): void
    {
        $missing = array_values(array_diff(fastermessage_translatable_strings(dirname(__DIR__)), array_keys($this->french())));

        self::assertSame([], $missing, 'chaînes sans traduction française');
    }

    public function testLesMarqueursDeRemplacementSontConserves(): void
    {
        foreach ($this->french() as $source => $translation) {
            preg_match_all('/%(?:\d+\$)?s/', $source, $expected);
            preg_match_all('/%(?:\d+\$)?s/', $translation, $actual);
            sort($expected[0]);
            sort($actual[0]);
            self::assertSame($expected[0], $actual[0], $source);
        }
    }

    public function testAucuneTraductionOrpheline(): void
    {
        $known = fastermessage_translatable_strings(dirname(__DIR__));
        $header = 'Send SMS and WhatsApp messages from WordPress with the FasterMessage API v2: from the admin screen or from your own code.';
        $orphans = array_values(array_diff(array_keys($this->french()), $known, [$header]));

        self::assertSame([], $orphans, 'traductions dont la chaîne source n’existe plus');
    }
}
