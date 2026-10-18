<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';
class CheckLocalEmail extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        if (getenv('MW_ENV') !== 'development' || $this->getConfig()->get('SMTP')['host'] !== 'mailpit') {
            throw new RuntimeException('Email capture test requires the local development Mailpit transport');
        }
        $subject = 'Docker SMTP smoke ' . bin2hex(random_bytes(8));
        $status = MediaWiki\Mail\UserMailer::send(
            new MediaWiki\Mail\MailAddress('recipient@example.invalid'),
            new MediaWiki\Mail\MailAddress('wiki@example.invalid'),
            $subject, 'Verify local development email capture without external delivery.'
        );
        if (!$status->isOK()) { throw new RuntimeException('Local SMTP send failed: ' . $status->getWikiText()); }
        $messages = json_decode(file_get_contents('http://mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($messages['messages'] ?? [] as $message) {
            if (($message['Subject'] ?? '') === $subject) {
                $this->output("MediaWiki SMTP message captured by local Mailpit.\n");
                return;
            }
        }
        throw new RuntimeException('SMTP accepted the message but Mailpit capture was not found');
    }
}
return CheckLocalEmail::class;
