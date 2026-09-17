<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Copertina del riquadro di avvio, per le lezioni di solo video.
 *
 * Non e' un'immagine caricata: e' un disegno generato, cosi' ogni lezione ha
 * la sua senza che nessuno debba preparare niente. Le tinte derivano
 * dall'identificativo della lezione, quindi la stessa lezione ha sempre lo
 * stesso aspetto e due lezioni vicine no.
 *
 * Toni pastello: saturazione bassa e luminosita' alta, perche' il riquadro
 * sta dietro a un pulsante e non deve gridare piu' di lui.
 */
class VideoPoster
{
    /**
     * @return string SVG pronto da stampare, senza dimensioni fisse: si adatta
     *                al riquadro con viewBox e preserveAspectRatio.
     */
    public static function svg(int $lessonId, string $title = ''): string
    {
        // Passo ampio fra una lezione e l'altra: numeri vicini danno tinte
        // lontane invece di sfumature che sembrano un errore.
        $hue = ($lessonId * 47) % 360;
        $hue2 = ($hue + 42) % 360;
        $hue3 = ($hue + 310) % 360;

        // Anche la composizione cambia, non solo il colore: altrimenti sei
        // lezioni in fila sembrano la stessa immagine ricolorata.
        $variante = $lessonId % 6;

        $bigR = 130 + ($lessonId * 17) % 70;          // cerchio grande
        $bigX = 60 + ($lessonId * 31) % 200;
        $bigY = ($variante % 2 === 0) ? 40 : 300;

        $smallR = 45 + ($lessonId * 11) % 45;
        $smallX = 380 + ($lessonId * 23) % 220;
        $smallY = ($variante % 3 === 0) ? 250 : 80;

        $blobRx = 150 + ($lessonId * 13) % 80;
        $blobRy = 100 + ($lessonId * 7) % 70;
        $blobX = ($variante % 2 === 0) ? 540 : 120;
        $blobY = ($variante % 2 === 0) ? 300 : 320;

        $wave = 200 + ($lessonId * 19) % 90;          // altezza delle onde
        $amp = 30 + ($lessonId * 9) % 45;             // quanto ondeggiano
        $wave2 = $wave + 35;

        $glowX = 220 + ($lessonId * 29) % 200;
        $glowY = 120 + ($lessonId * 37) % 120;

        // Il verso delle onde si alterna, così metà delle copertine ha il
        // movimento opposto.
        $sign = ($variante % 2 === 0) ? 1 : -1;
        $ampA = $amp * $sign;
        $ampB = -$amp * $sign;

        $id = 'poster' . $lessonId;

        return <<<SVG
<svg class="video-poster" viewBox="0 0 640 360" preserveAspectRatio="xMidYMid slice"
     xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="{$id}-bg" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="hsl({$hue} 62% 90%)"/>
            <stop offset="1" stop-color="hsl({$hue2} 58% 84%)"/>
        </linearGradient>
        <radialGradient id="{$id}-glow" cx="0.5" cy="0.5" r="0.5">
            <stop offset="0" stop-color="hsl({$hue3} 70% 96%)" stop-opacity="0.95"/>
            <stop offset="1" stop-color="hsl({$hue3} 70% 92%)" stop-opacity="0"/>
        </radialGradient>
    </defs>

    <rect width="640" height="360" fill="url(#{$id}-bg)"/>

    <g opacity="0.7">
        <circle cx="{$bigX}" cy="{$bigY}" r="{$bigR}" fill="hsl({$hue3} 60% 93%)"/>
        <ellipse cx="{$blobX}" cy="{$blobY}" rx="{$blobRx}" ry="{$blobRy}" fill="hsl({$hue2} 64% 91%)"/>
        <circle cx="{$smallX}" cy="{$smallY}" r="{$smallR}" fill="hsl({$hue} 58% 95%)"/>
    </g>

    <g opacity="0.5" stroke="hsl({$hue} 42% 64%)" stroke-width="2" fill="none">
        <path d="M-20 {$wave} q 160 {$ampA} 330 0 t 350 {$ampB}"/>
        <path d="M-20 {$wave2} q 160 {$ampB} 330 0 t 350 {$ampA}" opacity="0.6"/>
    </g>

    <circle cx="{$glowX}" cy="{$glowY}" r="150" fill="url(#{$id}-glow)"/>
</svg>
SVG;
    }
}
