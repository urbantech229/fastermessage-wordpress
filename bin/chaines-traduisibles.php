<?php
/**
 * CHAÎNES TRADUISIBLES de l'extension (domaine `fastermessage`), lues par
 * l'analyseur lexical de PHP — pas par une expression rationnelle, que les
 * apostrophes échappées et les retours à la ligne tromperaient.
 *
 * Usage : php bin/chaines-traduisibles.php   → une chaîne par ligne
 * Le test `TranslationTest` s'en sert pour exiger une traduction française de
 * chaque chaîne : un libellé ajouté sans traduction fait échouer les tests.
 */

declare(strict_types=1);

/** @return list<string> chaînes source, triées, sans doublon */
function fastermessage_translatable_strings(string $root): array
{
    $functions = ['__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e'];
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $tokens = array_values(array_filter(
            token_get_all(file_get_contents($file->getPathname())),
            static fn ($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], $functions, true)) {
                continue;
            }
            $text = $tokens[$index + 2] ?? null;
            $comma = $tokens[$index + 3] ?? null;
            $domain = $tokens[$index + 4] ?? null;
            if (($tokens[$index + 1] ?? null) !== '(' || !is_array($text) || $text[0] !== T_CONSTANT_ENCAPSED_STRING
                || $comma !== ',' || !is_array($domain) || trim($domain[1], '\'"') !== 'fastermessage') {
                continue;
            }
            // Chaîne entre apostrophes : seuls \' et \\ y sont des échappements.
            $found[str_replace(['\\\'', '\\\\'], ['\'', '\\'], substr($text[1], 1, -1))] = true;
        }
    }
    $strings = array_keys($found);
    sort($strings);

    return $strings;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo implode("\n", fastermessage_translatable_strings(dirname(__DIR__))), "\n";
}
