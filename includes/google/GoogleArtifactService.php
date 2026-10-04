<?php
/**
 * PEPP Learning ERP — Google Artifact Service
 *
 * Synchronizes Google Meet conference artifacts (recordings, transcripts, smart notes / Gemini notes)
 * into `session_google_artifacts` as metadata and direct links to Google Drive / Docs.
 *
 * SAFETY INVARIANT:
 * Does NOT download large video/audio files to the local Hostinger server.
 * Stores authenticated references and links directly to the organizer's Google Drive.
 */

declare(strict_types=1);

require_once __DIR__ . '/GoogleWorkspaceClient.php';

class GoogleArtifactService {
    protected GoogleWorkspaceClient $client;
    protected PDO $pdo;

    public function __construct(PDO $pdo, ?GoogleWorkspaceClient $client = null) {
        $this->pdo = $pdo;
        $this->client = $client ?: new GoogleWorkspaceClient();
    }

    public function getClient(): GoogleWorkspaceClient {
        return $this->client;
    }

    /**
     * Synchronize artifacts for an ERP session.
     *
     * @param int $sessionId
     * @return array{
     *     success: bool,
     *     artifacts_synced: int,
     *     error: ?string,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function syncSessionArtifacts(int $sessionId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return [
                'success' => false,
                'artifacts_synced' => 0,
                'error' => "Session #{$sessionId} not found.",
                'items' => [],
            ];
        }

        $spaceName = (string)($session['google_meet_space_name'] ?? '');
        $meetCode  = (string)($session['google_meet_code'] ?? '');

        if ($spaceName === '' && $meetCode === '') {
            return [
                'success' => false,
                'artifacts_synced' => 0,
                'error' => "Session #{$sessionId} does not have Google Meet space metadata.",
                'items' => [],
            ];
        }

        $confRecords = $this->fetchConferenceRecords($spaceName, $meetCode);
        if (empty($confRecords)) {
            return [
                'success' => true,
                'artifacts_synced' => 0,
                'error' => "No conference records found for artifacts sync.",
                'items' => [],
            ];
        }

        $syncedItems = [];

        foreach ($confRecords as $cRecord) {
            $recordName = (string)($cRecord['name'] ?? '');
            if ($recordName === '') continue;

            // 1. Sync Recordings
            $recordings = $this->fetchRecordings($recordName);
            foreach ($recordings as $rec) {
                $recName = (string)($rec['name'] ?? '');
                $driveFileId = (string)($rec['driveDestination']['file'] ?? '');
                $state = (string)($rec['state'] ?? 'active');
                $exportUri = (string)($rec['driveDestination']['exportUri'] ?? '');
                if ($exportUri === '' && $driveFileId !== '') {
                    $exportUri = 'https://drive.google.com/file/d/' . rawurlencode($driveFileId) . '/view';
                }

                $this->upsertArtifact($sessionId, 'recording', $recName, $driveFileId ?: null, $state, $exportUri ?: null);
                $syncedItems[] = [
                    'type'     => 'recording',
                    'resource' => $recName,
                    'file_id'  => $driveFileId,
                    'url'      => $exportUri,
                    'state'    => $state,
                ];
            }

            // 2. Sync Transcripts
            $transcripts = $this->fetchTranscripts($recordName);
            foreach ($transcripts as $tr) {
                $trName = (string)($tr['name'] ?? '');
                $docId = (string)($tr['docsDestination']['document'] ?? '');
                $state = (string)($tr['state'] ?? 'active');
                $docUri = (string)($tr['docsDestination']['exportUri'] ?? '');
                if ($docUri === '' && $docId !== '') {
                    $docUri = 'https://docs.google.com/document/d/' . rawurlencode($docId) . '/edit';
                }

                $this->upsertArtifact($sessionId, 'transcript', $trName, $docId ?: null, $state, $docUri ?: null);
                $syncedItems[] = [
                    'type'     => 'transcript',
                    'resource' => $trName,
                    'file_id'  => $docId,
                    'url'      => $docUri,
                    'state'    => $state,
                ];
            }

            // 3. Sync Smart Notes / Gemini Notes
            $smartNotes = $this->fetchSmartNotes($recordName);
            foreach ($smartNotes as $sn) {
                $snName = (string)($sn['name'] ?? '');
                $docId = (string)($sn['docsDestination']['document'] ?? $sn['driveDestination']['file'] ?? '');
                $state = (string)($sn['state'] ?? 'active');
                $docUri = (string)($sn['docsDestination']['exportUri'] ?? $sn['driveDestination']['exportUri'] ?? '');
                if ($docUri === '' && $docId !== '') {
                    $docUri = 'https://docs.google.com/document/d/' . rawurlencode($docId) . '/edit';
                }

                $this->upsertArtifact($sessionId, 'smart_notes', $snName, $docId ?: null, $state, $docUri ?: null);
                $syncedItems[] = [
                    'type'     => 'smart_notes',
                    'resource' => $snName,
                    'file_id'  => $docId,
                    'url'      => $docUri,
                    'state'    => $state,
                ];
            }
        }

        $nowDt = date('Y-m-d H:i:s');
        $this->pdo->prepare("UPDATE sessions SET google_last_sync_at = ? WHERE id = ?")->execute([$nowDt, $sessionId]);

        return [
            'success'          => true,
            'artifacts_synced' => count($syncedItems),
            'error'            => null,
            'items'            => $syncedItems,
        ];
    }

    /**
     * Retrieve structured summary of artifacts for this session.
     * Categorizes into Recording, Transcript, and Smart Notes / Gemini Notes.
     *
     * @param int $sessionId
     * @return array<string, mixed>
     */
    public function getSessionArtifactsSummary(int $sessionId): array {
        $stmt = $this->pdo->prepare("
            SELECT artifact_type, google_resource_name, drive_file_id, artifact_state, artifact_url, updated_at
            FROM session_google_artifacts
            WHERE session_id = ?
            ORDER BY artifact_type ASC, id DESC
        ");
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'recording'   => ['available' => false, 'status' => 'NOT AVAILABLE', 'state' => 'not_available', 'url' => null, 'file_id' => null, 'resource' => null],
            'transcript'  => ['available' => false, 'status' => 'NOT AVAILABLE', 'state' => 'not_available', 'url' => null, 'file_id' => null, 'resource' => null],
            'smart_notes' => ['available' => false, 'status' => 'NOT AVAILABLE', 'state' => 'not_available', 'url' => null, 'file_id' => null, 'resource' => null],
            'items'       => [],
        ];

        foreach ($rows as $r) {
            $type = (string)$r['artifact_type'];
            $state = (string)($r['artifact_state'] ?? 'active');
            $url = !empty($r['artifact_url']) ? (string)$r['artifact_url'] : null;
            $fileId = !empty($r['drive_file_id']) ? (string)$r['drive_file_id'] : null;
            $resName = (string)($r['google_resource_name'] ?? '');

            $upperState = strtoupper($state);
            $isAvailable = ($url !== null || $fileId !== null) && ($state === 'active' || $upperState === 'FILE_GENERATED');
            $statusText = $isAvailable ? 'AVAILABLE' : (in_array($upperState, ['STARTED', 'PROCESSING', 'GENERATING', 'ENDED'], true) ? 'PROCESSING' : 'NOT AVAILABLE');

            $info = [
                'available' => $isAvailable,
                'status'    => $statusText,
                'state'     => $state,
                'url'       => $url,
                'file_id'   => $fileId,
                'resource'  => $resName,
            ];

            if (isset($summary[$type]) && !$summary[$type]['available']) {
                $summary[$type] = $info;
            }

            $summary['items'][] = [
                'type'       => $type,
                'resource'   => $resName,
                'file_id'    => $fileId,
                'state'      => $state,
                'status'     => $statusText,
                'url'        => $url,
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ];
        }

        $isConsolidated = (!empty($summary['transcript']['file_id'])
            && !empty($summary['smart_notes']['file_id'])
            && $summary['transcript']['file_id'] === $summary['smart_notes']['file_id']);
        $summary['is_consolidated_notes_and_transcript'] = $isConsolidated;
        $summary['transcript']['is_consolidated'] = $isConsolidated;
        $summary['smart_notes']['is_consolidated'] = $isConsolidated;

        return $summary;
    }

    protected function fetchConferenceRecords(string $spaceName, string $meetCode): array {
        $filter = '';
        if ($spaceName !== '') {
            $cleanSpace = str_starts_with($spaceName, 'spaces/') ? $spaceName : ('spaces/' . $spaceName);
            $filter = 'space.name="' . addslashes($cleanSpace) . '"';
        } elseif ($meetCode !== '') {
            $filter = 'space.meeting_code="' . addslashes($meetCode) . '"';
        }

        $url = 'https://meet.googleapis.com/v2/conferenceRecords' . ($filter !== '' ? ('?filter=' . rawurlencode($filter)) : '');
        $res = $this->client->apiRequest('GET', $url, null, [], [GoogleWorkspaceClient::SCOPE_MEET_READONLY]);

        if ($res['success'] && !empty($res['data']['conferenceRecords'])) {
            return (array)$res['data']['conferenceRecords'];
        }

        return [];
    }

    protected function fetchRecordings(string $conferenceRecordName): array {
        $url = 'https://meet.googleapis.com/v2/' . $conferenceRecordName . '/recordings';
        $res = $this->client->apiRequest('GET', $url, null, [], [GoogleWorkspaceClient::SCOPE_MEET_READONLY]);

        if ($res['success'] && !empty($res['data']['recordings'])) {
            return (array)$res['data']['recordings'];
        }

        return [];
    }

    protected function fetchTranscripts(string $conferenceRecordName): array {
        $url = 'https://meet.googleapis.com/v2/' . $conferenceRecordName . '/transcripts';
        $res = $this->client->apiRequest('GET', $url, null, [], [GoogleWorkspaceClient::SCOPE_MEET_READONLY]);

        if ($res['success'] && !empty($res['data']['transcripts'])) {
            return (array)$res['data']['transcripts'];
        }

        return [];
    }

    protected function fetchSmartNotes(string $conferenceRecordName): array {
        $url = 'https://meet.googleapis.com/v2/' . $conferenceRecordName . '/smartNotes';
        $res = $this->client->apiRequest('GET', $url, null, [], [GoogleWorkspaceClient::SCOPE_MEET_READONLY]);

        if ($res['success'] && !empty($res['data']['smartNotes'])) {
            return (array)$res['data']['smartNotes'];
        }

        return [];
    }

    public function upsertArtifact(
        int $sessionId,
        string $artifactType,
        string $resourceName,
        ?string $driveFileId,
        string $artifactState,
        ?string $artifactUrl
    ): void {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = "
                INSERT INTO session_google_artifacts (
                    session_id, artifact_type, google_resource_name, drive_file_id,
                    artifact_state, artifact_url, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ON CONFLICT(session_id, artifact_type, google_resource_name) DO UPDATE SET
                    drive_file_id = excluded.drive_file_id,
                    artifact_state = excluded.artifact_state,
                    artifact_url = excluded.artifact_url,
                    updated_at = datetime('now')
            ";
        } else {
            $sql = "
                INSERT INTO session_google_artifacts (
                    session_id, artifact_type, google_resource_name, drive_file_id,
                    artifact_state, artifact_url, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    drive_file_id = VALUES(drive_file_id),
                    artifact_state = VALUES(artifact_state),
                    artifact_url = VALUES(artifact_url),
                    updated_at = NOW()
            ";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $sessionId,
            $artifactType,
            $resourceName,
            $driveFileId,
            $artifactState,
            $artifactUrl,
        ]);
    }
}
