<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use ExpressPHP\Database\Database;
use ExpressPHP\Database\Model;
use ExpressPHP\Http\Request;

final class ActivityLog extends Model
{
    protected string $table = 'activity_logs';
    protected array $fillable = [
        'user_id',
        'user_name',
        'description',
        'ip_address',
        'user_agent',
    ];

    public function latest(int $limit, int $offset = 0, ?string $date = null): array
    {
        $where = $date === null ? '' : ' WHERE created_at >= :date AND created_at < :next_date';
        $statement = Database::connection()->prepare(
            'SELECT * FROM activity_logs' . $where . ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        if ($date !== null) {
            $statement->bindValue('date', $date);
            $statement->bindValue('next_date', (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'));
        }
        $statement->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $statement->execute();
        return $this->presentAll($statement->fetchAll());
    }

    public function countForDate(?string $date = null): int
    {
        if ($date === null) {
            return $this->count();
        }

        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM activity_logs ' .
            'WHERE created_at >= :date AND created_at < :next_date'
        );
        $statement->execute([
            'date' => $date,
            'next_date' => (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'),
        ]);
        return (int)$statement->fetchColumn();
    }

    public function record(
        Request $request,
        string  $description,
    ): array {
        $user = $request->user();
        return $this->create([
            'user_id' => $user['id'] ?? null,
            'user_name' => (string)($user['name'] ?? $user['username'] ?? 'Unknown user'),
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
