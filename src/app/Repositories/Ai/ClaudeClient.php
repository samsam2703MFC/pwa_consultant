<?php
namespace App\Consultant\app\Repositories\Ai;

use Throwable;

/**
 * Appel à l'API Claude (Anthropic) — endpoint Messages.
 *
 * La clé n'est PAS committée : lue depuis config/anthropic.local.php (hors
 * Git, généré au déploiement depuis le secret ANTHROPIC_API_KEY). Clé absente
 * → available() renvoie false et l'écran n'affiche simplement pas le bouton.
 * Aucune fonctionnalité existante ne dépend d'elle.
 *
 * POURQUOI cURL ET PAS LE SDK — le SDK PHP officiel (anthropic-ai/sdk) exige
 * un client PSR-18 (Guzzle) et pèse ~1 800 fichiers ; ce dépôt VERSIONNE son
 * vendor/ et se déploie par rsync, donc ce serait ~3 000 fichiers de plus au
 * dépôt pour un unique POST. Le panel appelle déjà toutes ses API en cURL
 * (ApiClient, GoogleRatingRepository) ; on reste sur cette voie. Passer au SDK
 * est un `composer require` le jour où ça vaut le coût.
 */
class ClaudeClient
{
    private const URL     = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    private ?array $cfg = null;
    private bool $tried  = false;

    /** Motif du dernier échec — une correction muette est pire qu'un refus. */
    public ?string $lastError = null;

    /** La clé est-elle en place ? L'écran s'en sert pour afficher le bouton. */
    public function available(): bool
    {
        $c = $this->config();
        return $c !== null && !empty($c['api_key']);
    }

    /**
     * Un aller-retour, sans outil ni conversation : une consigne système, un
     * message, une réponse texte.
     *
     * @param array{model:string, max_tokens:int, system:string, prompt:string, effort?:string, timeout?:int} $q
     * @return string|null le texte rendu, ou null (motif dans lastError)
     */
    public function complete(array $q): ?string
    {
        $this->lastError = null;
        $cfg = $this->config();
        if ($cfg === null || empty($cfg['api_key'])) {
            $this->lastError = 'clé absente';
            return null;
        }

        $corps = [
            'model'      => (string)$q['model'],
            'max_tokens' => max(16, (int)$q['max_tokens']),
            'system'     => (string)$q['system'],
            'messages'   => [['role' => 'user', 'content' => (string)$q['prompt']]],
        ];
        // L'effort règle la profondeur de raisonnement. Une correction
        // orthographique n'en demande aucune : « low » évite d'attendre une
        // réflexion inutile. Vide = paramètre omis — tous les modèles ne le
        // connaissent pas, et un modèle plus ancien refuserait la requête.
        $effort = trim((string)($q['effort'] ?? ''));
        if ($effort !== '') {
            $corps['output_config'] = ['effort' => $effort];
        }

        [$code, $brut, $err] = $this->post($corps, (string)$cfg['api_key'], (int)($q['timeout'] ?? 20));

        if ($brut === null) {
            $this->lastError = $err !== '' ? $err : 'réseau';
            return null;
        }
        $json = json_decode($brut, true);

        if ($code !== 200) {
            $this->lastError = $this->motif($code, is_array($json) ? $json : null);
            error_log('[claude] HTTP ' . $code . ' — ' . substr($brut, 0, 300));
            return null;
        }
        if (!is_array($json)) {
            $this->lastError = 'réponse illisible';
            return null;
        }

        // L'ordre compte : un refus se lit AVANT le contenu. Sur un refus, le
        // tableau `content` est vide ou partiel — l'indexer aveuglément casse.
        $stop = (string)($json['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            $this->lastError = 'demande refusée par le modèle';
            return null;
        }
        if ($stop === 'max_tokens') {
            // Texte coupé au milieu : le rendre remplacerait la note par un
            // fragment. Mieux vaut ne rien proposer et le dire.
            $this->lastError = 'réponse tronquée (texte trop long)';
            return null;
        }

        // La réponse peut commencer par des blocs de réflexion : on prend le
        // premier bloc de TEXTE, pas le premier bloc tout court.
        foreach ((array)($json['content'] ?? []) as $bloc) {
            if (is_array($bloc) && ($bloc['type'] ?? '') === 'text' && isset($bloc['text'])) {
                $txt = trim((string)$bloc['text']);
                if ($txt !== '') {
                    return $txt;
                }
            }
        }

        $this->lastError = 'réponse vide';
        return null;
    }

    /** @return array{0:int,1:?string,2:string} [code HTTP, corps, erreur transport] */
    protected function post(array $corps, string $cle, int $timeout): array
    {
        try {
            $ch = curl_init(self::URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($corps, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => [
                    'x-api-key: ' . $cle,
                    'anthropic-version: ' . self::VERSION,
                    'content-type: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => max(5, $timeout),
                CURLOPT_ENCODING       => '',
            ]);
            $brut = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = (string)curl_error($ch);
            curl_close($ch);
            return [$code, $brut === false ? null : (string)$brut, $err];
        } catch (Throwable $e) {
            return [0, null, $e->getMessage()];
        }
    }

    /**
     * Un motif que le consultant peut comprendre. Un « erreur 429 » à l'écran
     * n'apprend rien à quelqu'un qui voulait juste corriger une phrase.
     */
    private function motif(int $code, ?array $json): string
    {
        $api = is_array($json['error'] ?? null) ? (string)($json['error']['message'] ?? '') : '';
        return match (true) {
            $code === 401 || $code === 403 => 'clé refusée',
            $code === 429                  => 'trop de demandes, réessayez dans un instant',
            $code === 529                  => 'service momentanément saturé',
            $code >= 500                   => 'service indisponible',
            $code === 400                  => 'requête refusée' . ($api !== '' ? ' : ' . $api : ''),
            default                        => 'erreur ' . $code,
        };
    }

    protected function config(): ?array
    {
        if ($this->tried) {
            return $this->cfg;
        }
        $this->tried = true;

        $file = __DIR__ . '/../../../../config/anthropic.local.php';
        if (!is_file($file)) {
            return null;
        }
        $c = require $file;
        if (!is_array($c) || empty($c['api_key']) || $c['api_key'] === 'REMPLACER_CLE') {
            return null;
        }
        return $this->cfg = $c;
    }
}
