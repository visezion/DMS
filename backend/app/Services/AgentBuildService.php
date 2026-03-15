<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class AgentBuildService
{
    public function build(string $version, string $runtime = 'win-x64', bool $selfContained = true): array
    {
        $safeVersion = $this->sanitizeToken($version, 'build');
        $safeRuntime = $this->sanitizeToken($runtime, 'win-x64');

        $agentRoot = $this->resolveAgentRepositoryRoot();
        $outputRoot = storage_path('app'.DIRECTORY_SEPARATOR.'agent-releases'.DIRECTORY_SEPARATOR.'builds');

        if ($agentRoot === null || ! is_dir($agentRoot)) {
            $candidates = $this->agentRepositoryCandidates();
            throw new \RuntimeException('Agent repository folder not found. Checked paths: '.implode(', ', $candidates));
        }

        if (! is_dir($outputRoot)) {
            mkdir($outputRoot, 0775, true);
        }

        $powerShellScript = base_path('scripts'.DIRECTORY_SEPARATOR.'build-agent.ps1');
        $shellScript = base_path('scripts'.DIRECTORY_SEPARATOR.'build-agent.sh');
        $powerShell = $this->resolvePowerShellBinary();

        if ($powerShell !== null) {
            if (! is_file($powerShellScript)) {
                throw new \RuntimeException('Build script missing: '.$powerShellScript);
            }
            $process = new Process([
                $powerShell,
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $powerShellScript,
                '-AgentRoot', $agentRoot,
                '-OutputRoot', $outputRoot,
                '-Version', $safeVersion,
                '-Runtime', $safeRuntime,
                '-SelfContained', $selfContained ? 'true' : 'false',
            ]);
        } else {
            if (! is_file($shellScript)) {
                throw new \RuntimeException('Build script missing: '.$shellScript);
            }
            $process = new Process([
                'sh',
                $shellScript,
                '--agent-root', $agentRoot,
                '--output-root', $outputRoot,
                '--version', $safeVersion,
                '--runtime', $safeRuntime,
                '--self-contained', $selfContained ? 'true' : 'false',
            ]);
        }

        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Agent build failed:\n".$process->getErrorOutput()."\n".$process->getOutput());
        }

        $output = trim($process->getOutput());
        preg_match('/Artifact:\s*(.+\.zip)/i', $output, $matches);
        $zipPath = $matches[1] ?? null;

        if (! $zipPath) {
            $candidates = glob($outputRoot.DIRECTORY_SEPARATOR.'dms-agent-'.$safeVersion.'-'.$safeRuntime.'-*.zip') ?: [];
            rsort($candidates);
            $zipPath = $candidates[0] ?? null;
        }

        if (! $zipPath || ! is_file($zipPath)) {
            throw new \RuntimeException('Build completed but ZIP artifact missing in '.$outputRoot);
        }

        return [
            'zip_name' => basename($zipPath),
            'zip_full_path' => $zipPath,
            'size_bytes' => filesize($zipPath),
            'sha256' => hash_file('sha256', $zipPath),
            'log' => $output,
        ];
    }

    private function sanitizeToken(string $value, string $fallback): string
    {
        $sanitized = preg_replace('/[^0-9A-Za-z._-]+/', '-', trim($value)) ?? '';
        $sanitized = trim($sanitized, '-');
        return $sanitized !== '' ? $sanitized : $fallback;
    }

    /**
     * @return array<int,string>
     */
    private function agentRepositoryCandidates(): array
    {
        $configured = trim((string) env('AGENT_BUILD_REPO_PATH', ''));
        $candidates = [];

        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $repoRoot = realpath(base_path('..')) ?: dirname(base_path());
        $candidates[] = $repoRoot.DIRECTORY_SEPARATOR.'agent';
        $candidates[] = base_path('agent');
        $candidates[] = '/var/www/agent';

        $unique = [];
        foreach ($candidates as $candidate) {
            $normalized = rtrim((string) $candidate, DIRECTORY_SEPARATOR);
            if ($normalized === '' || in_array($normalized, $unique, true)) {
                continue;
            }
            $unique[] = $normalized;
        }

        return $unique;
    }

    private function resolveAgentRepositoryRoot(): ?string
    {
        foreach ($this->agentRepositoryCandidates() as $candidate) {
            $resolved = realpath($candidate);
            $path = $resolved !== false ? $resolved : $candidate;
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    private function resolvePowerShellBinary(): ?string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return 'powershell';
        }

        foreach (['pwsh', 'powershell'] as $binary) {
            $found = trim((string) @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));
            if ($found !== '') {
                return $binary;
            }
        }

        return null;
    }
}
