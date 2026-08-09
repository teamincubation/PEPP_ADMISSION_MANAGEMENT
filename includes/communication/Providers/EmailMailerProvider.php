<?php
require_once __DIR__ . '/CommunicationProviderInterface.php';
require_once dirname(dirname(__DIR__)) . '/mailer.php';

class EmailMailerProvider implements CommunicationProviderInterface {
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        // Map attachments list for pepp_mail format
        $mappedAttachments = [];
        foreach ($attachments as $att) {
            if (isset($att['bytes'])) {
                $mappedAttachments[] = [
                    'name'  => $att['name'] ?? 'file.pdf',
                    'bytes' => $att['bytes'],
                    'type'  => $att['type'] ?? 'application/pdf'
                ];
            }
        }
        
        $sent = pepp_mail($to, $subject, $bodyHtml, $bodyText, $mappedAttachments);
        if ($sent) {
            return [
                'success' => true,
                'message_id' => 'mail_' . md5(uniqid('', true))
            ];
        }
        return false;
    }
}
