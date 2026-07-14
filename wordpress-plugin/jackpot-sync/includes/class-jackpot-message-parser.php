<?php
/**
 * Message parser + payload validator.
 *
 * Converts raw semicolon-separated DRGT messages (used by the admin tester)
 * into canonical payloads, and validates decoded JSON payloads coming from the
 * Cloudflare Worker. Never throws — always returns an array or WP_Error.
 *
 * @package JackpotSync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses and validates jackpot messages.
 */
class Jackpot_Sync_Message_Parser {

    /**
     * Parse a raw semicolon-separated line into a canonical payload.
     *
     *   JPCONFIG;<jpId>;<level>;<jpType>;<jpName>;<prizeName>;<CasID>
     *   JPUPDATE;<jpId>;<level>;<_>;<_>;<jpValue>;<jpShared>;<_>;<CasID>
     *
     * @param string $line Raw message line.
     * @return array<string,mixed>|WP_Error
     */
    public static function parse_line($line) {
        $line = trim((string) $line);
        if ($line === '') {
            return new WP_Error('empty_line', 'Message line is empty.');
        }

        $parts = array_map('trim', explode(';', $line));
        $type  = isset($parts[0]) ? strtoupper($parts[0]) : '';

        if ($type === 'JPCONFIG') {
            if (count($parts) < 7) {
                return new WP_Error('invalid_config', 'JPCONFIG requires at least 7 fields.');
            }

            return [
                'type'      => 'JPCONFIG',
                'jpId'      => $parts[1],
                'level'     => $parts[2],
                'jpType'    => $parts[3],
                'jpName'    => $parts[4],
                'prizeName' => $parts[5],
                'casId'     => $parts[6],
            ];
        }

        if ($type === 'JPUPDATE') {
            if (count($parts) < 9) {
                return new WP_Error('invalid_update', 'JPUPDATE requires at least 9 fields.');
            }

            return [
                'type'     => 'JPUPDATE',
                'jpId'     => $parts[1],
                'level'    => $parts[2],
                'jpValue'  => $parts[5],
                'jpShared' => $parts[6],
                'casId'    => $parts[8],
            ];
        }

        return new WP_Error('unsupported_type', 'Unsupported message type: ' . $type);
    }

    /**
     * Validate and normalize a decoded JSON payload.
     *
     * @param mixed $data Decoded payload (expected array).
     * @return array<string,mixed>|WP_Error Normalized payload or error.
     */
    public static function validate_payload($data) {
        if (!is_array($data)) {
            return new WP_Error('invalid_json', 'Payload is not a JSON object.', ['status' => 400]);
        }

        $type   = isset($data['type']) ? strtoupper(sanitize_text_field((string) $data['type'])) : '';
        $jp_id  = isset($data['jpId']) ? sanitize_text_field((string) $data['jpId']) : '';
        $cas_id = isset($data['casId']) ? sanitize_text_field((string) $data['casId']) : '';

        if ($type === '') {
            return new WP_Error('missing_type', 'Missing message type.', ['status' => 400]);
        }

        if ($jp_id === '') {
            return new WP_Error('missing_jpid', 'Missing jpId.', ['status' => 400]);
        }

        if ($cas_id === '') {
            return new WP_Error('missing_casid', 'Missing casId.', ['status' => 400]);
        }

        if ($type === 'JPCONFIG') {
            $normalized = [
                'type'      => 'JPCONFIG',
                'jpId'      => $jp_id,
                'casId'     => $cas_id,
                'level'     => isset($data['level']) ? sanitize_text_field((string) $data['level']) : '',
                'jpType'    => isset($data['jpType']) ? sanitize_text_field((string) $data['jpType']) : '',
                'jpName'    => isset($data['jpName']) ? sanitize_text_field((string) $data['jpName']) : '',
                'prizeName' => isset($data['prizeName']) ? sanitize_text_field((string) $data['prizeName']) : '',
            ];

            if ($normalized['jpName'] === '') {
                return new WP_Error('missing_jpname', 'JPCONFIG requires jpName.', ['status' => 400]);
            }

            return $normalized;
        }

        if ($type === 'JPUPDATE') {
            if (!isset($data['jpValue']) || !isset($data['jpShared'])) {
                return new WP_Error('missing_values', 'JPUPDATE requires jpValue and jpShared.', ['status' => 400]);
            }

            if (!is_numeric($data['jpValue']) || !is_numeric($data['jpShared'])) {
                return new WP_Error('non_numeric_values', 'jpValue and jpShared must be numeric.', ['status' => 400]);
            }

            return [
                'type'     => 'JPUPDATE',
                'jpId'     => $jp_id,
                'casId'    => $cas_id,
                'level'    => isset($data['level']) ? sanitize_text_field((string) $data['level']) : '',
                'jpValue'  => $data['jpValue'],
                'jpShared' => $data['jpShared'],
            ];
        }

        return new WP_Error('unknown_type', 'Unknown message type: ' . $type, ['status' => 400]);
    }
}
