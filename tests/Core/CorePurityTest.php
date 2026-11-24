<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * src/Core/ MUST contain no WordPress function, no network call, no file-system call and no
 * system clock (AGENTS.md, plan sections 4.1 and A-21). Comments are ignored.
 */
final class CorePurityTest extends TestCase
{
    private const FORBIDDEN = [
        'WordPress hook or option' => '/\b(add_action|add_filter|remove_action|remove_filter|do_action|apply_filters|get_option|update_option|add_option|delete_option|get_transient|set_transient|get_site_option)\s*\(/i',
        'WordPress post API' => '/\b(get_posts?|wp_insert_post|wp_update_post|wp_delete_post|get_post_meta|update_post_meta|add_post_meta|delete_post_meta|get_the_title|get_terms?|wp_set_object_terms|WP_Query)\b/',
        'WordPress wp_* function' => '/\bwp_[a-z0-9_]+\s*\(/i',
        'WordPress i18n or escaping' => '/(?<![\w>:$])(__|_e|_x|_n|esc_html|esc_attr|esc_url|sanitize_text_field|current_time|is_admin|current_user_can)\s*\(/',
        'WordPress constant' => '/\b(ABSPATH|WPINC|WP_CONTENT_DIR)\b/',
        'network' => '/\b(curl_[a-z_]+|fsockopen|pfsockopen|stream_socket_client|socket_create|socket_connect|get_headers|gethostbyname|dns_get_record)\s*\(/i',
        'file system' => '/(?<![\w$>:])(file_get_contents|file_put_contents|fopen|fwrite|file|readfile|unlink|scandir|glob|mkdir|rmdir|rename|copy|tempnam|is_file|is_dir|file_exists)\s*\(/i',
        'system clock' => '/(?<![\w$>:])(time|microtime|hrtime|date|gmdate|strtotime|mktime|date_create|getdate|localtime)\s*\(|new\s+\\\\?DateTime(Immutable)?\s*\(\s*(\)|[\'"]now[\'"])/i',
        'HTTP URL' => '/[\'"]https?:\/\//i',
    ];

    public function testCoreIsFreeOfWordPressNetworkFilesAndClock(): void
    {
        $dir = dirname(__DIR__, 2) . '/src/Core';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $checked = 0;
        $violations = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            ++$checked;
            $code = self::stripComments((string) file_get_contents($file->getPathname()));
            foreach (self::FORBIDDEN as $label => $pattern) {
                if (preg_match_all($pattern, $code, $m)) {
                    $violations[] = $file->getFilename() . ": $label: " . implode(', ', array_unique($m[0]));
                }
            }
        }
        self::assertGreaterThan(10, $checked);
        self::assertSame([], $violations);
    }

    public function testTheGuardCatchesViolations(): void
    {
        $bad = '<?php get_option("x"); wp_remote_get("y"); $t = time(); new DateTimeImmutable(); file_get_contents($p); __("Text", "d");';
        $hits = 0;
        foreach (self::FORBIDDEN as $pattern) {
            $hits += preg_match($pattern, self::stripComments($bad));
        }
        self::assertGreaterThanOrEqual(5, $hits);
    }

    private static function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
