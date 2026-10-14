<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLog;
use App\Models\EmailLog;
use DateTimeImmutable;
use ExpressPHP\Http\Pagination;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Mail\MailException;
use ExpressPHP\Mail\SMTPMailer;
use ExpressPHP\Support\UTF8;

final class EmailController
{
    public function __construct(
        private readonly EmailLog    $emails = new EmailLog(),
        private readonly ActivityLog $activities = new ActivityLog(),
        private readonly SMTPMailer  $mailer = new SMTPMailer(),
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'limit' => 'optional|integer|min:1|max:100',
            'offset' => 'optional|integer|min:0',
            'date' => 'optional|date_format:Y-m-d',
        ]);
        $limit = $data['limit'] ?? 20;
        $offset = $data['offset'] ?? 0;
        $date = $data['date'] ?? null;

        return $response->success(
            Pagination::payload(
                $this->emails->latest($limit, $offset, $date),
                $this->emails->countForDate($date),
                $limit,
                $offset,
            ),
            'Email logs loaded',
        );
    }

    public function show(Request $request, Response $response): Response
    {
        $params = $request->validateParams(['id' => 'required|integer|min:1']);
        $email = $this->emails->find($params['id']);
        return $email === null
            ? $response->error('Email log not found', 404)
            : $response->success($email, 'Email log loaded');
    }

    public function send(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'mailer' => 'optional|string|in:smtp,gmail',
            'to' => 'required|string|email|max:190',
            'subject' => 'required|string|min:1|max:190',
            'body' => 'required|string|min:1|max:100000',
            'html' => 'optional|boolean',
            'reply_to' => 'optional|nullable|string|email|max:190',
        ]);
        $user = $request->user();
        $mailer = $data['mailer'] ?? SMTPMailer::defaultMailer();

        try {
            $this->mailer->send(
                $mailer,
                $data['to'],
                $data['subject'],
                $data['body'],
                $data['html'] ?? true,
                $data['reply_to'] ?? null,
            );
            $email = $this->emails->create([
                ...$this->userDetails($user),
                'mailer' => $mailer,
                'recipient' => $data['to'],
                'subject' => $data['subject'],
                'status' => 'sent',
                'error_message' => null,
                'sent_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            $this->activities->record(
                $request,
                ($user['name'] ?? $user['username']) . ' sent an email to "' . $data['to'] . '"',
            );
            return $response->success($email, 'Email sent', 201);
        } catch (MailException $exception) {
            $this->emails->create([
                ...$this->userDetails($user),
                'mailer' => $mailer,
                'recipient' => $data['to'],
                'subject' => $data['subject'],
                'status' => 'failed',
                'error_message' => UTF8::truncate($exception->getMessage(), 2000),
                'sent_at' => null,
            ]);
            return $response->error('Email delivery failed', 502);
        }
    }

    private function userDetails(array $user): array
    {
        return [
            'user_id' => $user['id'] ?? null,
            'user_name' => (string)($user['name'] ?? $user['username'] ?? 'Unknown user'),
        ];
    }
}
