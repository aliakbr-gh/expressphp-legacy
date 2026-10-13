<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use ExpressPHP\Database\Database;
use ExpressPHP\Database\Model;

final class EmailLog extends Model
{
    protected string $table = 'email_logs';
    protected array $fillable = [
        'user_id',
        'user_name',
        'mailer',
        'recipient',
        'subject',
        'status',
        'error_message',
        'sent_at',
    ];
    protected array $dates = ['sent_at', 'created_at', 'updated_at'];

    public function latest(int $limit, int $offset = 0, ?string $date = null): array
    {
        $where = $date === null ? '' : ' WHERE created_at >= :date AND created_at < :next_date';
        $statement = Database::connection()->prepare(
            'SELECT * FROM email_logs' . $where . ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        if ($date !== null) {
            $statement->bindValue('date', $date);
            $statement->bindValue('next_date', $this->nextDate($date));
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
            'SELECT COUNT(*) FROM email_logs WHERE created_at >= :date AND created_at < :next_date'
        );
        $statement->execute(['date' => $date, 'next_date' => $this->nextDate($date)]);
        return (int)$statement->fetchColumn();
    }

    private function nextDate(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }
}
