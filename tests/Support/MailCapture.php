<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Throwable;
use Yiisoft\Mailer\MailerInterface;
use Yiisoft\Mailer\MessageInterface;
use Yiisoft\Mailer\SendResults;

final class MailCapture implements MailerInterface
{
    public function send(MessageInterface $message): void {}

    public function sendMultiple(array $messages): SendResults
    {
        $success = [];
        $fail = [];

        foreach ($messages as $message) {
            try {
                $this->send($message);
                $success[] = $message;
            } catch (Throwable $e) {
                $fail[] = ['message' => $message, 'error' => $e];
            }
        }

        return new SendResults($success, $fail);
    }
}
