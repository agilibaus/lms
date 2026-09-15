<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\Auth;
use App\Core\Database;

/**
 * Accesso dati per la tabella `role_permissions`.
 * I permessi sono configurabili a runtime (nessun privilegio hardcodato nel codice).
 */
class RolePermissionModel
{
    /**
     * Catalogo delle chiavi riconosciute dall'applicazione, con descrizione,
     * raggruppate per area. Il pannello admin costruisce la matrice da qui:
     * cosi' l'elenco resta allineato a cio' che il codice controlla davvero.
     *
     * @return array<string, array<string, string>>
     */
    public static function catalog(): array
    {
        return [
            'Corsi' => [
                'course.view' => 'Accedere ai corsi a cui si è iscritti',
                'course.create' => 'Creare nuovi corsi',
                'course.edit' => 'Modificare corsi, moduli e lezioni',
                'course.delete' => 'Eliminare corsi',
            ],
            'Quiz' => [
                'quiz.take' => 'Svolgere i quiz',
                'quiz.grade' => 'Gestire i quiz e consultare i tentativi',
                'quiz.grade_assigned' => 'Consultare i tentativi degli studenti seguiti',
            ],
            'Utenti e gruppi' => [
                'user.manage' => 'Gestire gli utenti (creazione, ruoli, password)',
                'assistant.manage' => 'Gestire i propri assistenti',
                'group.manage' => 'Gestire tutti i gruppi',
                'group.manage_own' => 'Gestire i gruppi di cui si è tutor',
            ],
            'Report e certificati' => [
                'report.view' => 'Consultare tutti i report',
                'report.view_assigned' => 'Consultare i report degli studenti seguiti',
                'certificate.issue' => 'Emettere e revocare certificati',
                'certificate.view_own' => 'Vedere e scaricare i propri certificati',
            ],
        ];
    }

    /**
     * @return string[] tutte le chiavi del catalogo, senza raggruppamento
     */
    public static function catalogKeys(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::catalog())));
    }

    /**
     * @return string[] elenco dei permission_key assegnati al ruolo indicato
     */
    public static function keysForRole(string $role): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT permission_key FROM role_permissions WHERE role = :role'
        );
        $stmt->execute(['role' => $role]);

        return array_column($stmt->fetchAll(), 'permission_key');
    }

    /**
     * Matrice completa: ruolo => elenco di chiavi.
     *
     * @return array<string, string[]>
     */
    public static function matrix(): array
    {
        $matrix = array_fill_keys(Auth::ROLES, []);

        $rows = Database::connection()
            ->query('SELECT role, permission_key FROM role_permissions ORDER BY role, permission_key')
            ->fetchAll();

        foreach ($rows as $row) {
            $matrix[$row['role']][] = $row['permission_key'];
        }

        return $matrix;
    }

    /**
     * Sostituisce in blocco i permessi di un ruolo.
     *
     * @param string[] $keys
     */
    public static function setForRole(string $role, array $keys): void
    {
        $db = Database::connection();
        $db->beginTransaction();

        try {
            $delete = $db->prepare('DELETE FROM role_permissions WHERE role = :role');
            $delete->execute(['role' => $role]);

            $insert = $db->prepare(
                'INSERT INTO role_permissions (role, permission_key) VALUES (:role, :permission_key)'
            );

            foreach (array_unique($keys) as $key) {
                $insert->execute(['role' => $role, 'permission_key' => $key]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Chiavi presenti in tabella ma non nel catalogo: permessi personalizzati
     * aggiunti a mano, che il pannello mostra senza cancellarli.
     *
     * @return string[]
     */
    public static function extraKeys(): array
    {
        $stored = array_column(
            Database::connection()->query('SELECT DISTINCT permission_key FROM role_permissions')->fetchAll(),
            'permission_key'
        );

        return array_values(array_diff($stored, self::catalogKeys()));
    }
}
