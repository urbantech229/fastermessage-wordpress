<?php
/**
 * EMBARQUE le SDK PHP officiel dans l'extension, sous un espace de noms préfixé.
 *
 * Usage : php bin/embarquer-sdk.php <chemin du dépôt fastermessage-php> <étiquette>
 *         php bin/embarquer-sdk.php ../fastermessage-php v0.2.0
 *
 * POURQUOI PRÉFIXER. WordPress charge toutes les extensions dans le même processus,
 * sans gestionnaire de dépendances commun. Si une autre extension (ou le thème)
 * embarque elle aussi `fastermessage/fastermessage-php`, dans une autre version,
 * la première classe `FasterMessage\Client` chargée gagne, et l'autre extension
 * tourne avec un SDK qu'elle ne connaît pas. Sous `FasterMessageWP\Sdk`, la copie
 * de l'extension n'appartient qu'à elle.
 *
 * Le SDK est lu depuis une ÉTIQUETTE (`git archive`), jamais depuis la copie de
 * travail : ce qui est embarqué correspond exactement à une version publiée.
 * Le résultat (`lib/sdk/`) est versionné avec l'extension : rien ne se télécharge
 * à l'installation sur un site.
 */

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage : php bin/embarquer-sdk.php <dépôt fastermessage-php> <étiquette>\n");
    exit(1);
}

[, $depot, $etiquette] = $argv;
// `--copie-de-travail` : DÉVELOPPEMENT SEULEMENT, pour éprouver l'extension contre
// un SDK pas encore publié. La version embarquée est alors marquée « non publiée » :
// avant toute livraison, ré-embarquer depuis l'étiquette.
$copieDeTravail = $etiquette === '--copie-de-travail';
if (!$copieDeTravail && preg_match('/^v\d+\.\d+\.\d+$/', $etiquette) !== 1) {
    fwrite(STDERR, "Étiquette attendue sous la forme vX.Y.Z (ou --copie-de-travail).\n");
    exit(1);
}

$cible = dirname(__DIR__) . '/lib/sdk';
$temporaire = sys_get_temp_dir() . '/fm-sdk-' . bin2hex(random_bytes(4));
mkdir($temporaire, 0777, true);

if ($copieDeTravail) {
    copierDossier($depot . '/src', $temporaire . '/src');
    copy($depot . '/LICENSE', $temporaire . '/LICENSE');
    $etiquette = 'copie de travail NON PUBLIÉE (' . date('Y-m-d H:i') . ')';
} else {
    $archive = $temporaire . '/sdk.tar';
    $commande = sprintf('git -C %s archive --format=tar -o %s %s src LICENSE', escapeshellarg($depot), escapeshellarg($archive), escapeshellarg($etiquette));
    exec($commande, $sortie, $code);
    if ($code !== 0) {
        fwrite(STDERR, "git archive a échoué (étiquette absente ?).\n");
        exit(1);
    }
    (new PharData($archive))->extractTo($temporaire);
}

supprimer($cible);
mkdir($cible, 0777, true);

$fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporaire . '/src', FilesystemIterator::SKIP_DOTS));
$nombre = 0;
foreach ($fichiers as $fichier) {
    if ($fichier->getExtension() !== 'php') {
        continue;
    }
    $relatif = substr($fichier->getPathname(), strlen($temporaire . '/src') + 1);
    $source = file_get_contents($fichier->getPathname());
    // Toutes les formes : `namespace FasterMessage…`, `use FasterMessage\…`, `\FasterMessage\…`.
    $source = preg_replace('/(?<![\w\\\\])FasterMessage(?=\\\\|;)/', 'FasterMessageWP\\\\Sdk', $source);
    $destination = $cible . '/' . str_replace('\\', '/', $relatif);
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0777, true);
    }
    file_put_contents($destination, $source);
    $nombre++;
}
copy($temporaire . '/LICENSE', $cible . '/LICENSE');
file_put_contents($cible . '/VERSION', $etiquette . "\n");
supprimer($temporaire);

echo "SDK {$etiquette} embarqué : {$nombre} fichier(s) dans lib/sdk, espace de noms FasterMessageWP\\Sdk.\n";

function copierDossier(string $source, string $destination): void
{
    mkdir($destination, 0777, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $element) {
        $cheminCible = $destination . '/' . substr($element->getPathname(), strlen($source) + 1);
        $element->isDir() ? mkdir($cheminCible, 0777, true) : copy($element->getPathname(), $cheminCible);
    }
}

function supprimer(string $chemin): void
{
    if (!file_exists($chemin)) {
        return;
    }
    if (is_file($chemin)) {
        unlink($chemin);
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $element) {
        $element->isDir() ? rmdir($element->getPathname()) : unlink($element->getPathname());
    }
    rmdir($chemin);
}
