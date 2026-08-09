<?php
/**
 * Interface defining communication channels providers.
 */
interface CommunicationProviderInterface {
    /**
     * Dispatches a single message payload over the provider channel.
     *
     * @param string $to Recipient phone number or email address
     * @param string $subject Message subject line (if applicable)
     * @param string $bodyHtml Main formatted message body HTML
     * @param string $bodyText Plaintext fallback message body
     * @param array $attachments Optional list of attachment specs
     * @param array $templateData Optional template components map (Meta Cloud variables)
     * @return array|bool Array containing status/message_id on success, false on failure
     */
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []);
}
