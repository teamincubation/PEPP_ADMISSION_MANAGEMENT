<?php
/**
 * PEPP Learning ERP — Google Meet Service
 *
 * Manages Google Meet Space configuration, access restriction, auto-artifacts
 * (recording, transcription, smart notes), and faculty COHOST assignment.
 *
 * API SPECIFICATION:
 * - Google Meet REST API v2
 * - Space resource: spaces/{space} (authoritative identifier)
 * - SpaceConfig: accessType = 'RESTRICTED', moderation = 'ON', artifactConfig
 * - Members: role = 'COHOST' for assigned faculty
 */

declare(strict_types=1);

require_once __DIR__ . '/GoogleWorkspaceClient.php';

class GoogleMeetService {
    protected GoogleWorkspaceClient $client;

    public function __construct(?GoogleWorkspaceClient $client = null) {
        $this->client = $client ?: new GoogleWorkspaceClient();
    }

    public function getClient(): GoogleWorkspaceClient {
        return $this->client;
    }

    /**
     * Resolve or discover the authoritative space resource name (spaces/{space})
     * from a meeting code or URI.
     *
     * @param string $meetingCodeOrUri e.g. "abc-defg-hij" or "https://meet.google.com/abc-defg-hij" or "spaces/..."
     * @return array{success: bool, space_name: ?string, meeting_code: ?string, error: ?string, raw: ?array<string, mixed>}
     */
    public function resolveSpace(string $meetingCodeOrUri): array {
        $input = trim($meetingCodeOrUri);
        $meetingCode = null;

        if (str_starts_with($input, 'spaces/')) {
            $spaceName = $input;
        } elseif (preg_match('/meet\.google\.com\/([a-z0-9\-]+)/i', $input, $m)) {
            $meetingCode = $m[1];
            $spaceName = 'spaces/' . $meetingCode;
        } else {
            $meetingCode = $input;
            $spaceName = 'spaces/' . $meetingCode;
        }

        $url = 'https://meet.googleapis.com/v2/' . $spaceName;
        $res = $this->client->apiRequest('GET', $url, null, [], [
            GoogleWorkspaceClient::SCOPE_MEET_READONLY,
        ]);

        // Resilience fallback: If meetings.space.readonly is not yet authorized in Google Workspace Admin Console DWD,
        // retry with meetings.space.settings which also permits space GET metadata
        if (!$res['success'] && ($res['status'] === 401 || $res['status'] === 0)) {
            $fallbackRes = $this->client->apiRequest('GET', $url, null, [], [
                GoogleWorkspaceClient::SCOPE_MEET_SETTINGS,
            ]);
            if ($fallbackRes['success']) {
                $res = $fallbackRes;
            }
        }

        if ($res['success'] && !empty($res['data'])) {
            $data = $res['data'];
            $canonicalName = (string)($data['name'] ?? $spaceName);
            $canonicalCode = (string)($data['meetingCode'] ?? $meetingCode);
            return [
                'success'      => true,
                'space_name'   => $canonicalName,
                'meeting_code' => $canonicalCode,
                'error'        => null,
                'raw'          => $data,
            ];
        }

        // If not found by meeting code, try spaces.create endpoint as fallback
        return [
            'success'      => false,
            'space_name'   => str_starts_with($input, 'spaces/') ? $input : null,
            'meeting_code' => $meetingCode,
            'error'        => $res['error'] ?: "Meet space could not be resolved for {$meetingCodeOrUri}",
            'raw'          => $res['data'],
        ];
    }

    /**
     * Create a standalone Google Meet space.
     *
     * @param string $accessType
     * @return array{success: bool, space_name: ?string, meeting_uri: ?string, meeting_code: ?string, error: ?string, raw: ?array<string, mixed>}
     */
    public function createSpace(string $accessType = 'RESTRICTED'): array {
        $url = 'https://meet.googleapis.com/v2/spaces';
        $payload = [
            'config' => [
                'accessType'       => $accessType,
                'entryPointAccess' => 'ALL',
                'moderation'       => 'ON',
                'attendanceReportGenerationType' => 'GENERATE_REPORT',
                'artifactConfig'   => [
                    'recordingConfig'     => ['autoRecordingGeneration'     => 'ON'],
                    'transcriptionConfig' => ['autoTranscriptionGeneration' => 'ON'],
                    'smartNotesConfig'    => ['autoSmartNotesGeneration'    => 'ON'],
                ],
            ],
        ];

        $res = $this->client->apiRequest('POST', $url, $payload, [], [
            GoogleWorkspaceClient::SCOPE_MEET_SPACE_REQ,
            GoogleWorkspaceClient::SCOPE_MEET_SETTINGS,
        ]);

        if ($res['success'] && !empty($res['data'])) {
            $data = $res['data'];
            return [
                'success'      => true,
                'space_name'   => (string)($data['name'] ?? ''),
                'meeting_uri'  => (string)($data['meetingUri'] ?? ''),
                'meeting_code' => (string)($data['meetingCode'] ?? ''),
                'error'        => null,
                'raw'          => $data,
            ];
        }

        return [
            'success'      => false,
            'space_name'   => null,
            'meeting_uri'  => null,
            'meeting_code' => null,
            'error'        => "Failed to create Google Meet space: " . ($res['error'] ?: 'Unknown error'),
            'raw'          => $res['data'],
        ];
    }

    /**
     * Configure Google Meet space settings:
     * - accessType = RESTRICTED
     * - moderation = ON
     * - autoRecordingGeneration = ON
     * - autoTranscriptionGeneration = ON
     * - autoSmartNotesGeneration = ON
     *
     * @param string $spaceName e.g. "spaces/sPaCeId"
     * @return array{success: bool, error: ?string, details: array<string, mixed>}
     */
    public function configureSpace(string $spaceName): array {
        $cleanSpace = trim($spaceName);
        if (!str_starts_with($cleanSpace, 'spaces/')) {
            $cleanSpace = 'spaces/' . $cleanSpace;
        }

        $url = 'https://meet.googleapis.com/v2/' . $cleanSpace . '?updateMask=config.accessType,config.entryPointAccess,config.moderation,config.attendanceReportGenerationType,config.artifactConfig.recordingConfig.autoRecordingGeneration,config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration,config.artifactConfig.smartNotesConfig.autoSmartNotesGeneration';

        $body = [
            'config' => [
                'accessType'       => 'RESTRICTED',
                'entryPointAccess' => 'ALL',
                'moderation'       => 'ON',
                'attendanceReportGenerationType' => 'GENERATE_REPORT',
                'artifactConfig'   => [
                    'recordingConfig'     => [
                        'autoRecordingGeneration' => 'ON',
                    ],
                    'transcriptionConfig' => [
                        'autoTranscriptionGeneration' => 'ON',
                    ],
                    'smartNotesConfig'    => [
                        'autoSmartNotesGeneration' => 'ON',
                    ],
                ],
            ],
        ];

        $res = $this->client->apiRequest('PATCH', $url, $body, [], [
            GoogleWorkspaceClient::SCOPE_MEET_SPACE_REQ,
            GoogleWorkspaceClient::SCOPE_MEET_SETTINGS,
        ]);

        if ($res['success']) {
            return [
                'success' => true,
                'error'   => null,
                'details' => $res['data'] ?? [],
            ];
        }

        // If specific artifactConfig fields failed (e.g. Gemini Smart Notes license required),
        // try fallback without smartNotesConfig to preserve recording & restriction
        if (str_contains(strtolower($res['error'] ?? ''), 'smartnotes') || str_contains(strtolower($res['error'] ?? ''), 'artifactconfig')) {
            $fallbackUrl = 'https://meet.googleapis.com/v2/' . $cleanSpace . '?updateMask=config.accessType,config.entryPointAccess,config.moderation,config.attendanceReportGenerationType,config.artifactConfig.recordingConfig.autoRecordingGeneration,config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration';
            $fallbackBody = [
                'config' => [
                    'accessType'       => 'RESTRICTED',
                    'entryPointAccess' => 'ALL',
                    'moderation'       => 'ON',
                    'attendanceReportGenerationType' => 'GENERATE_REPORT',
                    'artifactConfig'   => [
                        'recordingConfig'     => ['autoRecordingGeneration' => 'ON'],
                        'transcriptionConfig' => ['autoTranscriptionGeneration' => 'ON'],
                    ],
                ],
            ];
            $fbRes = $this->client->apiRequest('PATCH', $fallbackUrl, $fallbackBody, [], [
                GoogleWorkspaceClient::SCOPE_MEET_SPACE_REQ,
                GoogleWorkspaceClient::SCOPE_MEET_SETTINGS,
            ]);
            if ($fbRes['success']) {
                return [
                    'success' => true,
                    'error'   => "Configured with warning: Smart notes unavailable on domain; recording & moderation active.",
                    'details' => $fbRes['data'] ?? [],
                ];
            }
        }

        return [
            'success' => false,
            'error'   => "Meet space configuration failed: " . ($res['error'] ?: 'Unknown error'),
            'details' => $res['data'] ?? [],
        ];
    }

    /**
     * Add the faculty email as a Google Meet COHOST.
     *
     * @param string $spaceName e.g. "spaces/sPaCeId"
     * @param string $facultyEmail
     * @return array{success: bool, member_name: ?string, error: ?string}
     */
    public function addFacultyCohost(string $spaceName, string $facultyEmail): array {
        $cleanSpace = trim($spaceName);
        if (!str_starts_with($cleanSpace, 'spaces/')) {
            $cleanSpace = 'spaces/' . $cleanSpace;
        }

        $email = strtolower(trim($facultyEmail));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success'     => false,
                'member_name' => null,
                'error'       => "Invalid faculty email: {$facultyEmail}",
            ];
        }

        $url = 'https://meet.googleapis.com/v2/' . $cleanSpace . '/members';
        $body = [
            'email' => $email,
            'role'  => 'COHOST',
        ];

        $res = $this->client->apiRequest('POST', $url, $body, [], [
            GoogleWorkspaceClient::SCOPE_MEET_SPACE_REQ,
        ]);

        if ($res['success'] && !empty($res['data'])) {
            return [
                'success'     => true,
                'member_name' => (string)($res['data']['name'] ?? ''),
                'error'       => null,
            ];
        }

        // Idempotent handling: If 409 Conflict (member already exists), treat as success
        if ($res['status'] === 409) {
            return [
                'success'     => true,
                'member_name' => null,
                'error'       => null,
            ];
        }

        // Return error with descriptive context
        return [
            'success'     => false,
            'member_name' => null,
            'error'       => "Failed to add faculty co-host: " . ($res['error'] ?: 'Unknown error'),
        ];
    }
}
