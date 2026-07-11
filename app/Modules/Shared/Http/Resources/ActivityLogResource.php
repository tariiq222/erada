<?php

namespace App\Modules\Shared\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    private const REDACTED = '[REDACTED]';

    /**
     * Patterns used by redactUserAgent() to bucket a raw UA string into
     * its browser family. Order matters — Edge / Opera must be matched
     * before Chrome / Safari since their UA strings contain both.
     *
     * @var array<string, string>
     */
    private const UA_PATTERNS = [
        'Edge' => '/Edg\//i',
        'Opera' => '/OPR\/|Opera\//i',
        'Firefox' => '/Firefox\//i',
        'Chrome' => '/Chrome\//i',
        'Safari' => '/Safari\//i',
        'curl' => '/^curl\//i',
        'wget' => '/^wget\//i',
        'Postman' => '/Postman/i',
        'Insomnia' => '/Insomnia\//i',
        'bot' => '/bot|crawler|spider|slurp/i',
    ];

    /**
     * Phase 1B — cluster cross-org surfaces a minimal audit envelope.
     *
     * For rows whose organization differs from the actor's, the free-form
     * `description`, `reason`, `old_values`, `new_values`, and `metadata`
     * columns carry module business content that is gated by ITS OWN
     * module capability (projects.view, tasks.view, hr.view, ovr.view,
     * …). Reading the audit row that *mentions* those changes must not
     * surface the change itself.
     *
     * The polymorphism (loggable_type / loggable_id) stays — those are
     * stable routing keys — but accessing the pointed-to record still
     * goes through THAT module's policy.
     *
     * IP / UA redaction is MANDATORY for every actor regardless of
     * capability (CFA-11). It is preserved in the minimal envelope.
     */
    private bool $minimalEnvelope = false;

    /**
     * Construct a resource that emits the cluster cross-org minimal
     * envelope instead of the full redacted audit detail. Identifiers,
     * action, model label/type, scope, coarse actor identity, timestamps,
     * IP / UA CIDR / family, and the routing keys remain; the five
     * sensitive free-text / change-log columns are returned as JSON
     * `null` so the shape is stable for downstream consumers.
     */
    public static function forClusterCrossOrg(Model $log): self
    {
        $r = new self($log);
        $r->minimalEnvelope = true;

        return $r;
    }

    public function toArray(Request $request): array
    {
        return $this->minimalEnvelope
            ? $this->minimalAuditEnvelope()
            : $this->fullAuditDetail();
    }

    /**
     * Full redacted audit detail — same shape CFA-11 produced for every
     * authorized reader. Same-org auditors get this after the universal
     * secret / credential / network / direct-PII key redaction in
     * `redact()`. Free-text fields (description, reason) are passed
     * through verbatim; only the historical sensitive KEY names inside
     * structured columns (old_values / new_values / metadata) are scrubbed.
     */
    private function fullAuditDetail(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => $this->action_label,
            'action_color' => $this->action_color,
            'description' => $this->description,
            'model_label' => $this->model_label,
            'loggable_type' => $this->loggable_type ? class_basename($this->loggable_type) : null,
            'loggable_id' => $this->loggable_id,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'role' => $this->role,
            'reason' => $this->reason,
            'old_values' => $this->redact($this->old_values),
            'new_values' => $this->redact($this->new_values),
            'metadata' => $this->redact($this->metadata),
            // Phase CFA-11 — IP / UA redaction is mandatory for every
            // actor; the cluster_auditor role does NOT widen this surface.
            'ip_address' => $this->redactIpAddress($this->ip_address),
            'user_agent' => $this->redactUserAgent($this->user_agent),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Minimal audit envelope for cluster cross-org. The same stable
     * shape — every key is present so the frontend contract does not
     * have to special-case — but the five sensitive columns are
     * returned as JSON `null` instead of leaking the underlying
     * structured / free-text content. IP / UA surface as the same
     * CFA-11 CIDR / browser family.
     */
    private function minimalAuditEnvelope(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => $this->action_label,
            'action_color' => $this->action_color,
            'description' => null,
            'model_label' => $this->model_label,
            'loggable_type' => $this->loggable_type ? class_basename($this->loggable_type) : null,
            'loggable_id' => $this->loggable_id,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'role' => $this->role,
            'reason' => null,
            'old_values' => null,
            'new_values' => null,
            'metadata' => null,
            'ip_address' => $this->redactIpAddress($this->ip_address),
            'user_agent' => $this->redactUserAgent($this->user_agent),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            if ($this->isSensitiveKey((string) $key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = is_array($item) ? $this->redact($item) : $item;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/^(is_confidential|severity_level|status)$|'.
            'token|password|secret|email|authorization|cookie|header|ip[_-]?address|user[_-]?agent|'.
            '^patient[_-]|^reporter[_-]?email$|^reporter[_-]?(name|extension|job_title|department_id|section_id)$|'.
            '^(incident_description|actions_taken|closure_reason|reopen_reason)$/i',
            $key
        ) === 1;
    }

    /**
     * Reduce a raw IP address to a coarse CIDR that survives the audit
     * purpose (cluster / network / approximate geography) without
     * exposing the host octet. IPv4 ⇒ /24, IPv6 ⇒ /48. Unparseable
     * values fall back to a family hash so the column can never leak a
     * raw address via a malformed input.
     */
    private function redactIpAddress(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $trimmed = trim($ip);

        $ipv4Cidr = $this->redactIpv4($trimmed);
        if ($ipv4Cidr !== null) {
            return $ipv4Cidr;
        }

        $ipv6Cidr = $this->redactIpv6($trimmed);
        if ($ipv6Cidr !== null) {
            return $ipv6Cidr;
        }

        return 'unknown:'.substr(hash('sha256', $trimmed), 0, 12);
    }

    private function redactIpv4(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return 'ipv4:'.substr(hash('sha256', $ip), 0, 12);
        }

        return $parts[0].'.'.$parts[1].'.'.$parts[2].'.0/24';
    }

    private function redactIpv6(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return 'ipv6:'.substr(hash('sha256', $ip), 0, 12);
        }
        // /48 ⇒ keep first 3 groups (6 bytes).
        $hex = bin2hex($packed);
        $group1 = substr($hex, 0, 4);
        $group2 = substr($hex, 4, 4);
        $group3 = substr($hex, 8, 4);

        return $group1.':'.$group2.':'.$group3.'::/48';
    }

    /**
     * Reduce a raw User-Agent string to its browser family (no version,
     * no OS details, no device fingerprint). Bots and CLI tools are
     * bucketed explicitly so a security audit can still tell a curl probe
     * from a Chrome session without leaking the precise build.
     */
    private function redactUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $trimmed = trim($userAgent);

        foreach (self::UA_PATTERNS as $family => $pattern) {
            if (preg_match($pattern, $trimmed) === 1) {
                return $family;
            }
        }

        return 'other';
    }
}
