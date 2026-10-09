<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Il benvenuto del tutor all'inizio di un corso (07/10, chiesto da Elena):
 * la foto del tutor, un breve audio con il testo scritto, in cima alla
 * pagina del corso.
 *
 * LE DECISIONI
 *   - **Uno per tutor e per corso.** Lo studente sente il tutor del proprio
 *     gruppo (`TutorWelcomeModel::forStudent()`); senza un tutor, cioe'
 *     iscritto dal catalogo senza gruppo, non vede niente.
 *   - **La foto e' quella del profilo** (09/10, Alessandro; fino alla 0167
 *     era una foto caricata apposta, una per corso): il tutor ha un volto
 *     solo in tutta la piattaforma, e ne ha sempre uno
 *     (`AvatarImage::obbligatoria()`).
 *   - **Completo all'inizio, poi ridotto a una riga**: dalla quarta visita,
 *     o appena l'audio e' stato ascoltato fino in fondo — la prima delle due.
 *   - **Lo carica il tutor**, il proprio (`course.welcome_own`), e l'admin
 *     per tutti (`course.welcome`): cosi' dalla 0135.
 *
 * Qui stanno la regola completo/ridotto, senza database, e il salvataggio
 * dell'audio. Come per la copertina del corso, sostituire l'audio cancella
 * quello vecchio: e' un file che appartiene a una riga sola, e una volta
 * sostituito non serve a nessuno.
 */
final class TutorWelcome
{
    /** Le visite in cui il benvenuto e' completo; dalla successiva e' ridotto. */
    public const VISITE_COMPLETE = 3;

    /**
     * Un audio breve: uno o due minuti stanno in un paio di MB. Il limite
     * evita che diventi per sbaglio un audio di mezz'ora servito da PHP, che
     * occuperebbe un processo per tutta la durata (§7.2 del promemoria).
     */
    public const AUDIO_MAX_BYTES = 5 * 1024 * 1024;

    public const AUDIO_EXTENSIONS = ['mp3', 'm4a'];

    /**
     * I tipi che un MP3 o un M4A risultano a `finfo`. L'estensione da sola
     * si cambia rinominando il file: si controlla anche il contenuto.
     */
    private const AUDIO_MIME = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4', 'application/octet-stream'];

    /**
     * Completo o ridotto, per la visita appena registrata.
     *
     * @param int  $visite    quante volte lo studente ha aperto la pagina, compresa questa
     * @param bool $ascoltato se l'audio e' stato ascoltato fino in fondo
     */
    public static function modo(int $visite, bool $ascoltato): string
    {
        return !$ascoltato && $visite <= self::VISITE_COMPLETE ? 'completo' : 'ridotto';
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return string percorso relativo a /storage
     */
    public static function storeAudio(array $file, int $courseId): string
    {
        if ($file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';

            if (!in_array($mime, self::AUDIO_MIME, true)) {
                throw new \RuntimeException('Il file audio non è un MP3 o un M4A (risulta «' . $mime . '»).');
            }
        }

        return Upload::store($file, 'welcomes/' . $courseId, self::AUDIO_EXTENSIONS, self::AUDIO_MAX_BYTES)['stored_path'];
    }

    /**
     * Il link di invito al gruppo WhatsApp, come lo scrive chi gestisce il
     * gruppo. Vuoto vuol dire nessun link. Si accettano solo gli inviti di
     * WhatsApp (`https://chat.whatsapp.com/…`): un errore di copia e incolla
     * non deve mandare gli studenti su un altro sito.
     *
     * @throws \InvalidArgumentException con la frase da mostrare
     */
    public static function whatsappUrl(string $valore): ?string
    {
        $valore = trim($valore);

        if ($valore === '') {
            return null;
        }

        if (!preg_match('~^https://chat\.whatsapp\.com/[A-Za-z0-9_-]{6,}(?:\?[^\s]*)?$~', $valore) || mb_strlen($valore) > 255) {
            throw new \InvalidArgumentException(
                'Il link di invito in WhatsApp deve cominciare con https://chat.whatsapp.com/ ed è quello '
                . 'che WhatsApp dà con «Invita tramite link».'
            );
        }

        return $valore;
    }

    /**
     * L'email che il tutor da' ai suoi studenti. Vuota vuol dire nessuna.
     *
     * @throws \InvalidArgumentException con la frase da mostrare
     */
    public static function contactEmail(string $valore): ?string
    {
        $valore = trim($valore);

        if ($valore === '') {
            return null;
        }

        if (filter_var($valore, FILTER_VALIDATE_EMAIL) === false || mb_strlen($valore) > 255) {
            throw new \InvalidArgumentException('L\'email per gli studenti non è un indirizzo valido.');
        }

        return $valore;
    }

    public static function audioMime(string $relativePath): string
    {
        return strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) === 'm4a' ? 'audio/mp4' : 'audio/mpeg';
    }

    public static function delete(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '' || !str_starts_with($relativePath, 'welcomes/')) {
            return;
        }

        $absolute = Upload::absolutePath($relativePath);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
