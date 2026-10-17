<?php

declare(strict_types=1);

namespace App\WhatsApp;

/**
 * Providers that support Meta-style approved message templates.
 * Only the official Meta Cloud API implements this; unofficial gateways do not.
 */
interface TemplateProvider
{
    /**
     * Submit a template for approval.
     * @param array<string,mixed> $payload {name, language, category, components}
     * @return array{ok:bool,id?:string,status?:string,error?:string}
     */
    public function createTemplate(array $account, array $credentials, array $payload): array;

    /** @return array{ok:bool,templates?:list<array<string,mixed>>,error?:string} */
    public function listTemplates(array $account, array $credentials): array;

    /** @return array{ok:bool,error?:string} */
    public function deleteTemplate(array $account, array $credentials, string $name, ?string $templateId): array;

    /**
     * Upload a sample file for a media header (resumable upload) and return its handle.
     * @return array{ok:bool,handle?:string,error?:string}
     */
    public function uploadTemplateSample(array $account, array $credentials, string $path, string $mime, string $filename): array;

    /**
     * Upload a file for sending (returns a media id usable instead of a public link).
     * @return array{ok:bool,id?:string,error?:string}
     */
    public function uploadMedia(array $account, array $credentials, string $path, string $mime, string $filename): array;

    /** @param list<array<string,mixed>> $components send-time parameters */
    public function sendTemplate(array $account, array $credentials, string $toE164, string $name, string $language, array $components): SendResult;
}
