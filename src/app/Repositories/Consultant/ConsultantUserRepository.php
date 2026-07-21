<?php
namespace App\Consultant\app\Repositories\Consultant;

use App\Consultant\core\Db\Database;
use Throwable;

/**
 * Données utilisateur des consultants — SOURCE DE VÉRITÉ : les tables
 * `user_membership`, `user_profile`, `position`, `position_consultant_areas`
 * et `of_tag` (leviers et couleurs officiels, table transversale) de la base
 * locale. Aucune donnée dupliquée d'ailleurs.
 *
 * Les schémas n'étant pas documentés, toutes les colonnes sont détectées
 * dynamiquement (mêmes principes que TodoTaskRepository) ; table ou colonne
 * introuvable → section vide, l'affichage masque proprement.
 */
class ConsultantUserRepository
{
    /** @var array<string, array<int, string>> cache des colonnes par table */
    private array $cols = [];

    /**
     * Agrège les données du consultant depuis les tables de référence.
     *
     * @return array{
     *   membership: array<string,mixed>,
     *   profile: array<string,mixed>,
     *   position: array<string,mixed>,
     *   areas: array<int,string>
     * }
     */
    public function getConsultantData(int $membershipId): array
    {
        $empty = ['membership' => [], 'profile' => [], 'position' => [], 'areas' => []];
        $pdo = Database::pdo();
        if ($pdo === null || $membershipId <= 0) {
            return $empty;
        }

        try {
            $membership = $this->findRowById($pdo, 'user_membership', $membershipId) ?? [];

            // user_profile : par l'id utilisateur du membership, sinon par le
            // membership lui-même (selon le sens de la FK dans le schéma).
            $profile = [];
            $userId = $this->intVal($membership, ['id_user', 'user_id', 'id_account', 'account_id']);
            if ($userId !== null) {
                $profile = $this->findRowBy($pdo, 'user_profile', ['id_user', 'user_id', 'id_account', 'account_id', 'id'], $userId) ?? [];
            }
            if ($profile === []) {
                $profile = $this->findRowBy($pdo, 'user_profile', ['id_user_membership', 'user_membership_id', 'id_membership', 'membership_id'], $membershipId) ?? [];
            }

            // position : FK du membership, sinon via position_consultant_areas.
            $positionId = $this->intVal($membership, ['id_position', 'position_id']);
            $areas      = [];
            $pcaRows    = $this->findRowsBy(
                $pdo,
                'position_consultant_areas',
                ['id_user_membership', 'user_membership_id', 'id_membership', 'membership_id', 'id_consultant', 'consultant_id', 'id_user', 'user_id'],
                [$membershipId, $userId]
            );
            foreach ($pcaRows as $row) {
                if ($positionId === null) {
                    $positionId = $this->intVal($row, ['id_position', 'position_id']);
                }
                $area = $this->areaLabel($pdo, $row);
                if ($area !== null && !in_array($area, $areas, true)) {
                    $areas[] = $area;
                }
            }

            $position = $positionId !== null
                ? ($this->findRowById($pdo, 'position', $positionId) ?? [])
                : [];

            return [
                'membership' => $membership,
                'profile'    => $profile,
                'position'   => $position,
                'areas'      => $areas,
            ];
        } catch (Throwable $e) {
            error_log('[db] ConsultantUserRepository::getConsultantData échoué: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Leviers et couleurs OFFICIELS (table transversale `of_tag`) : référence
     * partagée par tous les modules. [['id','name','color'], …] triés par nom.
     *
     * @return array<int, array{id:int, name:string, color:?string}>
     */
    public function getOfficialTags(): array
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return [];
        }

        try {
            $names = $this->columns($pdo, 'of_tag');
            if ($names === []) {
                return [];
            }

            $nameCol  = $this->pickColumn($names, ['name', 'title', 'label', 'tag', 'tag_name'])
                ?? $this->pickColumnContaining($names, 'name');
            if ($nameCol === null) {
                return [];
            }
            $idCol    = $this->pickColumn($names, ['id']) ?? $names[0];
            $colorCol = $this->pickColumn($names, ['color', 'colour', 'hex', 'color_hex', 'hex_color', 'color_code', 'code_color'])
                ?? $this->pickColumnContaining($names, 'color');
            $activeCol = $this->pickColumn($names, ['active', 'is_active', 'enabled']);

            $sql = 'SELECT `' . $idCol . '` AS id, `' . $nameCol . '` AS name'
                 . ($colorCol !== null ? ', `' . $colorCol . '` AS color' : '')
                 . ' FROM of_tag'
                 . ($activeCol !== null ? ' WHERE `' . $activeCol . '` = 1' : '')
                 . ' ORDER BY `' . $nameCol . '` LIMIT 100';

            $out = [];
            foreach ($pdo->query($sql)->fetchAll() as $row) {
                $label = trim((string)$row['name']);
                if ($label === '') {
                    continue;
                }
                $color = isset($row['color']) ? trim((string)$row['color']) : '';
                if ($color !== '' && $color[0] !== '#' && preg_match('/^[0-9a-fA-F]{3,8}$/', $color)) {
                    $color = '#' . $color; // hex stocké sans dièse
                }
                $out[] = ['id' => (int)$row['id'], 'name' => $label, 'color' => $color !== '' ? $color : null];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[db] ConsultantUserRepository::getOfficialTags échoué: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Libellé d'une zone d'intervention à partir d'une ligne de
     * position_consultant_areas : colonne texte area/zone si présente, sinon
     * résolution de l'id vers une table area/consultant_area/zone (nom
     * détecté), sinon null.
     */
    private function areaLabel(\PDO $pdo, array $row): ?string
    {
        foreach ($row as $col => $val) {
            if (!preg_match('/(area|zone)/i', (string)$col)) {
                continue;
            }
            if (is_string($val) && trim($val) !== '' && !ctype_digit(trim($val))) {
                return trim($val);
            }
            if (is_numeric($val)) {
                foreach (['area', 'consultant_area', 'zone', 'of_area'] as $table) {
                    $ref = $this->findRowById($pdo, $table, (int)$val);
                    if ($ref !== null) {
                        $names = array_keys($ref);
                        $nameCol = $this->pickColumn($names, ['name', 'title', 'label'])
                            ?? $this->pickColumnContaining($names, 'name');
                        if ($nameCol !== null && trim((string)$ref[$nameCol]) !== '') {
                            return trim((string)$ref[$nameCol]);
                        }
                    }
                }
                return null;
            }
        }
        return null;
    }

    /** Colonnes d'une table (cache par requête), [] si table absente. */
    private function columns(\PDO $pdo, string $table): array
    {
        if (!isset($this->cols[$table])) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
                );
                $stmt->execute([':t' => $table]);
                $this->cols[$table] = array_map(fn($r) => (string)$r['COLUMN_NAME'], $stmt->fetchAll());
            } catch (Throwable $e) {
                $this->cols[$table] = [];
            }
        }
        return $this->cols[$table];
    }

    private function pickColumn(array $names, array $candidates): ?string
    {
        foreach ($candidates as $cand) {
            foreach ($names as $n) {
                if (strcasecmp($n, $cand) === 0) {
                    return $n;
                }
            }
        }
        return null;
    }

    private function pickColumnContaining(array $names, string $needle): ?string
    {
        foreach ($names as $n) {
            if (stripos($n, $needle) !== false) {
                return $n;
            }
        }
        return null;
    }

    private function findRowById(\PDO $pdo, string $table, int $id): ?array
    {
        $names = $this->columns($pdo, $table);
        if ($names === []) {
            return null;
        }
        $idCol = $this->pickColumn($names, ['id']) ?? $names[0];
        return $this->fetchOne($pdo, $table, $idCol, $id);
    }

    /** Première ligne dont l'une des colonnes candidates vaut $value. */
    private function findRowBy(\PDO $pdo, string $table, array $colCandidates, int $value): ?array
    {
        $names = $this->columns($pdo, $table);
        if ($names === []) {
            return null;
        }
        $col = $this->pickColumn($names, $colCandidates);
        if ($col === null) {
            return null;
        }
        return $this->fetchOne($pdo, $table, $col, $value);
    }

    /**
     * Lignes dont l'une des colonnes candidates vaut l'une des valeurs
     * fournies (premier couple colonne/valeur qui renvoie des résultats).
     *
     * @param array<int, int|null> $values
     */
    private function findRowsBy(\PDO $pdo, string $table, array $colCandidates, array $values): array
    {
        $names = $this->columns($pdo, $table);
        if ($names === []) {
            return [];
        }
        foreach ($colCandidates as $cand) {
            $col = $this->pickColumn($names, [$cand]);
            if ($col === null) {
                continue;
            }
            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }
                try {
                    $stmt = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE `' . $col . '` = :v LIMIT 50');
                    $stmt->execute([':v' => $value]);
                    $rows = $stmt->fetchAll();
                    if ($rows !== []) {
                        return $rows;
                    }
                } catch (Throwable $e) {
                    // colonne au type inattendu → candidat suivant
                }
            }
        }
        return [];
    }

    private function fetchOne(\PDO $pdo, string $table, string $col, int $value): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE `' . $col . '` = :v LIMIT 1');
            $stmt->execute([':v' => $value]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Premier entier trouvé parmi les colonnes candidates d'une ligne. */
    private function intVal(array $row, array $candidates): ?int
    {
        foreach ($candidates as $cand) {
            foreach ($row as $col => $val) {
                if (strcasecmp((string)$col, $cand) === 0 && is_numeric($val) && (int)$val > 0) {
                    return (int)$val;
                }
            }
        }
        return null;
    }
}
