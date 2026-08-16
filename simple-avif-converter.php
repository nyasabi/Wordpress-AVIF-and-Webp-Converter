<?php
/**
 * Plugin Name: Simple AVIF / WebP Converter
 * Description: 既存のメディア画像（JPEG/PNG）をAVIF・WebPに変換し、対応ブラウザに <picture> で配信します。共有レンタルサーバー向けにAjaxバッチ処理で動作。削減容量も集計します。
 * Version:     1.5.0
 * Author:      wasabi
 * License: AGPL3.0    
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

/*
 * v1.5.0 変更点（v1.4.0 からの変更）
 * - [削除] LQIP（lazyload中の低解像度ぼかしプレースホルダー）機能を削除
 *          既存サイトに残った _sac_lqip メタはアンインストール時に掃除されます
 *
 * v1.4.0 変更点（v1.3.0 からの追加）
 * - [追加] 画像サイズ属性の補完: width/height 属性の無い <img> に添付メタから寸法を付与（CLS対策、デフォルトOFF）
 * - [追加] 変換エラー一覧: 変換に失敗した添付を管理画面に一覧表示し、個別に再試行・履歴クリアが可能に
 * - [追加] メモリガード: GD変換前に必要メモリを見積もり、memory_limit 超過が見込まれる巨大画像を
 *          スキップしてエラー一覧に記録（共有サーバーでの致命的エラー対策、デフォルトON）
 *
 * v1.3.0 変更点（v1.2.1 からの追加）
 * - [追加] Lazyload: すべての画像に loading="lazy" / decoding="async" を付与（デフォルトOFF）
 *          ※他プラグイン・サーバー機能との二重適用に注意（設定画面に注意書きあり）
 * - [追加] LCP画像の優先読み込み: ページ先頭N枚を lazyload から除外して fetchpriority="high" を付与、
 *          投稿・固定ページのアイキャッチを <link rel="preload"> で先読み（デフォルトON）
 * - [追加] バックグラウンド処理: アップロード時の自動変換を WP-Cron / Action Scheduler の
 *          キュー処理に変更（大きい画像での同期変換タイムアウト対策）
 * - [追加] 既存メディアのバックグラウンド一括変換（ブラウザを閉じても進行）
 *
 * v1.2.1 変更点（v1.2.0 からの修正）
 * - [修正] GDフォールバックの EXIF Orientation 5/7（鏡像+回転）の回転方向が逆だった
 * - [修正] フルサイズのみ肥大化しサムネイルは変換成功した場合に削減量が記録されなかった
 * - [修正] 再変換で派生ファイルが全て削除された場合、過去の削減量が累計に残り続けていた
 * - [強化] URL→パス変換で uploads の兄弟ディレクトリ（例: uploads-x）へのすり抜けを拒否
 * - [性能] AVIF/WebP 対応判定（Imagick::queryFormats 等）を静的キャッシュ化
 *
 * v1.2.0 変更点（v1.1.0 からの修正）
 * - [修正] 添付ファイル削除時に .avif/.webp 派生ファイルを削除し、累計削減量からも減算するように
 * - [修正] 変換結果が元画像より大きい場合は派生ファイルを削除し、配信もしないように
 *          （再エンコードの繰り返しを防ぐため「肥大化した」記録を postmeta に保持）
 * - [修正] GDフォールバック時に EXIF Orientation を反映（回転・反転の補正）
 * - [修正] 一括変換のキューを transient（全IDの一括保持）からオフセットクエリ方式に変更
 *          （巨大メディアライブラリでのメモリ圧迫・外部オブジェクトキャッシュの1MB制限対策）
 * - [修正] 累計削減量の加算をSQLレベルのアトミック更新に変更（同時実行時の加算消失対策）
 * - [修正] 品質設定を変更したら既存の派生ファイルを再変換するように（品質スタンプ方式）
 * - [強化] URL→パス変換でパストラバーサル（"..".）を拒否
 * - [整理] 未使用引数の削除、アンインストール時のオプション・メタ・派生ファイルのクリーンアップ追加
 */

if (!defined('ABSPATH')) {
    exit;
}

class Simple_AVIF_Converter {

    const OPT           = 'sac_settings';
    const META_DONE     = '_sac_converted';
    const META_FMT      = '_sac_formats';
    const META_ORIG     = '_sac_original_bytes';
    const META_OPT      = '_sac_optimized_bytes';
    const META_SAVED    = '_sac_saved_bytes';
    const META_OVERSIZE = '_sac_oversized';      // 「変換すると元より大きくなった」記録
    const OPT_TOTAL     = 'sac_total_saved';
    const STAMP_PREFIX  = 'sac_stamp_';          // sac_stamp_avif / sac_stamp_webp（品質変更時刻）
    const OPT_BG        = 'sac_bg_state';        // バックグラウンド一括変換の進行状態
    const OPT_ERRORS    = 'sac_error_log';       // 変換エラー一覧（attachment_id => 詳細）
    const LOCK_BG       = 'sac_bg_lock';         // バッチ多重実行防止ロック（transient）
    const CRON_SINGLE   = 'sac_convert_single_event';
    const CRON_BATCH    = 'sac_bg_batch_event';

    /** フロントで処理した <img> の通し番号（LCP判定用） */
    private $img_index = 0;

    /** convert_one() の直近のエラー内容（エラー一覧の記録用） */
    private $last_error = '';

    public function __construct() {
        add_action('admin_menu',  [$this, 'add_menu']);
        add_action('admin_init',  [$this, 'register_settings']);

        add_action('wp_ajax_sac_scan',          [$this, 'ajax_scan']);
        add_action('wp_ajax_sac_convert_batch', [$this, 'ajax_convert_batch']);
        add_action('wp_ajax_sac_bg_start',      [$this, 'ajax_bg_start']);
        add_action('wp_ajax_sac_bg_cancel',     [$this, 'ajax_bg_cancel']);
        add_action('wp_ajax_sac_bg_status',     [$this, 'ajax_bg_status']);
        add_action('wp_ajax_sac_retry',         [$this, 'ajax_retry']);
        add_action('wp_ajax_sac_clear_errors',  [$this, 'ajax_clear_errors']);

        add_filter('wp_generate_attachment_metadata', [$this, 'on_generate_metadata'], 20, 2);

        // バックグラウンド処理（WP-Cron / Action Scheduler から呼ばれる）
        add_action(self::CRON_SINGLE, [$this, 'cron_convert_single']);
        add_action(self::CRON_BATCH,  [$this, 'cron_process_batch']);

        // 添付削除時に派生ファイルを掃除（ファイル削除前に発火するフック）
        add_action('delete_attachment', [$this, 'on_delete_attachment'], 10, 1);

        // メディア一覧（リスト表示）の列
        add_filter('manage_media_columns',         [$this, 'media_column_header']);
        add_action('manage_media_custom_column',   [$this, 'media_column_content'], 10, 2);
        // メディア詳細（グリッドのモーダル等）
        add_filter('attachment_fields_to_edit',    [$this, 'attachment_field'], 10, 2);

        if (!is_admin()) {
            if ($this->opt('frontend_rewrite', true) || $this->opt('lazyload')
                || $this->opt('lcp_boost') || $this->opt('add_dimensions')) {
                add_filter('wp_content_img_tag',  [$this, 'filter_img_tag'], 20, 3);
                add_filter('post_thumbnail_html', [$this, 'filter_thumbnail_html'], 20, 3);
            }
            if ($this->opt('lcp_boost')) {
                add_action('wp_head', [$this, 'preload_lcp'], 2);
            }
        }
    }

    /* ===================== 設定 ===================== */

    private function defaults() {
        return [
            'enable_avif'      => self::can_avif() ? 1 : 0,
            'enable_webp'      => self::can_webp() ? 1 : 0,
            'avif_quality'     => 50,
            'webp_quality'     => 78,
            'batch_size'       => 3,
            'auto_convert'     => 0,
            'frontend_rewrite' => 1,
            'lazyload'         => 0,
            'lcp_boost'        => 1,
            'lcp_count'        => 1,
            'add_dimensions'   => 0,
            'memory_guard'     => 1,
        ];
    }

    private function opt($key, $fallback = null) {
        $o = wp_parse_args(get_option(self::OPT, []), $this->defaults());
        return array_key_exists($key, $o) ? $o[$key] : $fallback;
    }

    public function register_settings() {
        register_setting('sac_group', self::OPT, [$this, 'sanitize']);
    }

    public function sanitize($in) {
        $old = wp_parse_args(get_option(self::OPT, []), $this->defaults());

        $new = [
            'enable_avif'      => !empty($in['enable_avif']) && self::can_avif() ? 1 : 0,
            'enable_webp'      => !empty($in['enable_webp']) && self::can_webp() ? 1 : 0,
            'avif_quality'     => max(1, min(100, (int)($in['avif_quality'] ?? 50))),
            'webp_quality'     => max(1, min(100, (int)($in['webp_quality'] ?? 78))),
            'batch_size'       => max(1, min(20, (int)($in['batch_size'] ?? 3))),
            'auto_convert'     => !empty($in['auto_convert']) ? 1 : 0,
            'frontend_rewrite' => !empty($in['frontend_rewrite']) ? 1 : 0,
            'lazyload'         => !empty($in['lazyload']) ? 1 : 0,
            'lcp_boost'        => !empty($in['lcp_boost']) ? 1 : 0,
            'lcp_count'        => max(1, min(10, (int)($in['lcp_count'] ?? 1))),
            'add_dimensions'   => !empty($in['add_dimensions']) ? 1 : 0,
            'memory_guard'     => !empty($in['memory_guard']) ? 1 : 0,
        ];

        // 品質が変わったら「品質スタンプ」を更新 → 既存の派生ファイルは再変換対象になる
        if ((int)$new['avif_quality'] !== (int)$old['avif_quality']) {
            update_option(self::STAMP_PREFIX . 'avif', time(), false);
        }
        if ((int)$new['webp_quality'] !== (int)$old['webp_quality']) {
            update_option(self::STAMP_PREFIX . 'webp', time(), false);
        }

        return $new;
    }

    /** フォーマットごとの「品質変更時刻」。これより古い派生ファイルは再変換する。 */
    private static function quality_stamp($format) {
        return (int) get_option(self::STAMP_PREFIX . $format, 0);
    }

    /** 有効化されているフォーマットの配列を返す */
    private function enabled_formats() {
        $f = [];
        if ($this->opt('enable_avif') && self::can_avif()) $f[] = 'avif';
        if ($this->opt('enable_webp') && self::can_webp()) $f[] = 'webp';
        return $f;
    }

    /* ===================== エンジン判定 ===================== */

    public static function can_avif() {
        static $cache = null;
        if ($cache !== null) return $cache;

        if (self::has_imagick_format('avif')) {
            return $cache = true;
        }
        if (function_exists('imageavif')) {
            $gd = function_exists('gd_info') ? gd_info() : [];
            if (!empty($gd['AVIF Support'])) return $cache = true;
        }
        return $cache = false;
    }

    public static function can_webp() {
        static $cache = null;
        if ($cache !== null) return $cache;

        if (self::has_imagick_format('webp')) {
            return $cache = true;
        }
        if (function_exists('imagewebp')) {
            $gd = function_exists('gd_info') ? gd_info() : [];
            if (!empty($gd['WebP Support'])) return $cache = true;
        }
        return $cache = false;
    }

    private static function has_imagick_format($fmt) {
        static $cache = [];
        if (array_key_exists($fmt, $cache)) return $cache[$fmt];

        $ok = false;
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            $ok = !empty(@Imagick::queryFormats(strtoupper($fmt)));
        }
        return $cache[$fmt] = $ok;
    }

    /* ===================== 1ファイル → 1フォーマット変換 ===================== */

    private static function variant_path($source, $format) {
        return $source . '.' . $format; // photo.jpg.avif / photo.jpg.webp
    }

    /**
     * GDで画像を読み込み、JPEGの場合はEXIF Orientationを反映して返す。
     *
     * @return resource|\GdImage|false
     */
    private static function gd_load($source, $ext) {
        $img = ($ext === 'png') ? @imagecreatefrompng($source) : @imagecreatefromjpeg($source);
        if (!$img) return false;

        if ($ext === 'png') {
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);
            return $img;
        }

        // JPEG: EXIF Orientation 補正（派生ファイルはEXIFを持たないため、ここで回転を焼き込む）
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $orientation = !empty($exif['Orientation']) ? (int)$exif['Orientation'] : 1;

            if ($orientation > 1 && $orientation <= 8) {
                // 2,4,5,7 は鏡像を含む
                if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
                    imageflip($img, IMG_FLIP_HORIZONTAL);
                }
                $angle = 0;
                switch ($orientation) {
                    case 3: case 4: $angle = 180; break;
                    case 6: case 7: $angle = -90; break; // 90°時計回り
                    case 5: case 8: $angle = 90;  break; // 90°反時計回り
                }
                if ($angle !== 0) {
                    $rotated = imagerotate($img, $angle, 0);
                    if ($rotated) {
                        imagedestroy($img);
                        $img = $rotated;
                    }
                }
            }
        }
        return $img;
    }

    /**
     * @param string $source  元ファイルの絶対パス
     * @param string $format  'avif' | 'webp'
     * @param int    $quality 1-100
     * @return string 'converted' | 'skipped' | 'error'
     */
    private function convert_one($source, $format, $quality) {
        $this->last_error = '';
        if (!is_string($source) || $source === '' || !file_exists($source)) {
            $this->last_error = 'ファイルが見つかりません';
            return 'error';
        }
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return 'skipped';
        }
        $dest = self::variant_path($source, $format);

        // 元画像より新しく、かつ品質変更後に作られた派生ファイルがあればスキップ
        $threshold = max((int) filemtime($source), self::quality_stamp($format));
        if (file_exists($dest) && filemtime($dest) >= $threshold) {
            return 'skipped';
        }

        try {
            if (self::has_imagick_format($format)) {
                $im = new Imagick();
                $im->readImage($source);
                if (method_exists($im, 'autoOrientImage')) {
                    @$im->autoOrientImage();
                }
                $im->stripImage();
                $im->setImageFormat($format);
                $im->setImageCompressionQuality($quality);
                if ($format === 'avif') {
                    @$im->setOption('heic:speed', '6'); // エンコード高速化
                } elseif ($format === 'webp') {
                    @$im->setOption('webp:method', '4');
                }
                $ok = $im->writeImage($dest);
                $im->clear();
                $im->destroy();
                if (!$ok) $this->last_error = strtoupper($format) . ' の書き出しに失敗しました (Imagick)';
                return $ok ? 'converted' : 'error';
            }

            // GDフォールバック
            // メモリガード: GDは全ピクセルをメモリ展開するため、巨大画像で memory_limit を
            // 超えて致命的エラー（白画面）になるのを事前見積もりで防ぐ。
            // Imagick はピクセルキャッシュをディスクに退避できるため対象外。
            if ($this->opt('memory_guard')) {
                $detail = '';
                if (!self::memory_ok($source, $detail)) {
                    $this->last_error = 'メモリ不足の恐れがあるためスキップしました（' . $detail . '）';
                    return 'error';
                }
            }
            if ($format === 'avif' && function_exists('imageavif')) {
                $img = self::gd_load($source, $ext);
                if (!$img) {
                    $this->last_error = '画像のデコードに失敗しました (GD)';
                    return 'error';
                }
                $ok = imageavif($img, $dest, $quality);
                imagedestroy($img);
                if (!$ok) $this->last_error = 'AVIF の書き出しに失敗しました (GD)';
                return $ok ? 'converted' : 'error';
            }
            if ($format === 'webp' && function_exists('imagewebp')) {
                $img = self::gd_load($source, $ext);
                if (!$img) {
                    $this->last_error = '画像のデコードに失敗しました (GD)';
                    return 'error';
                }
                $ok = imagewebp($img, $dest, $quality);
                imagedestroy($img);
                if (!$ok) $this->last_error = 'WebP の書き出しに失敗しました (GD)';
                return $ok ? 'converted' : 'error';
            }
        } catch (\Throwable $e) {
            error_log('[Simple AVIF/WebP Converter] ' . $e->getMessage() . ' (' . $source . ')');
            $this->last_error = $e->getMessage();
            return 'error';
        }
        $this->last_error = strtoupper($format) . ' に対応する変換エンジンがありません';
        return 'error';
    }

    /**
     * GDでのデコードに必要なメモリを見積もり、memory_limit に収まるか判定する。
     * GDはトゥルーカラーで1pxあたり約5バイト消費し、EXIF回転時は一時的に2枚分
     * 保持するため、安全係数込みで1pxあたり11バイトで見積もる。
     *
     * @param string $source 元ファイルの絶対パス
     * @param string $detail 判定NG時の詳細メッセージ（参照渡し）
     */
    private static function memory_ok($source, &$detail = '') {
        $limit = function_exists('wp_convert_hr_to_bytes')
            ? wp_convert_hr_to_bytes((string) ini_get('memory_limit'))
            : (int) ini_get('memory_limit');
        if ($limit <= 0) return true; // 無制限

        $info = @getimagesize($source);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return true; // 見積もり不能なら通す（デコード失敗は別途エラーとして記録される）
        }

        $needed    = (int) ($info[0] * $info[1] * 11) + 2 * 1024 * 1024;
        $available = $limit - memory_get_usage(true);

        if ($needed > $available) {
            $detail = sprintf(
                '%d×%dpx / 必要約%s・空き約%s',
                $info[0], $info[1],
                size_format($needed), size_format(max(0, $available))
            );
            return false;
        }
        return true;
    }

    /* ===================== 添付1件を変換 ===================== */

    private static function attachment_files($attachment_id) {
        $full = get_attached_file($attachment_id);
        if (!$full) return [];
        $dir   = dirname($full);
        $files = [$full];
        $meta  = wp_get_attachment_metadata($attachment_id);
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) $files[] = $dir . '/' . $size['file'];
            }
        }
        if (!empty($meta['original_image'])) {
            $files[] = $dir . '/' . $meta['original_image'];
        }
        return array_values(array_unique($files));
    }

    /**
     * @return array counts + saved_delta（今回の削減量の増減）
     */
    private function convert_attachment($attachment_id, $formats) {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }

        $files  = self::attachment_files($attachment_id);
        $counts = ['converted' => 0, 'skipped' => 0, 'error' => 0];
        $orig_total = 0;
        $opt_total  = 0;
        $errors     = []; // この添付で発生したエラー（エラー一覧に記録）

        // 「変換すると元より大きくなった」記録（無駄な再エンコードを防ぐ）
        $oversized = get_post_meta($attachment_id, self::META_OVERSIZE, true);
        if (!is_array($oversized)) $oversized = [];
        $oversized_dirty = false;

        $present_map = []; // どのフォーマットの派生ファイルが実際に存在したか

        foreach ($files as $f) {
            if (!file_exists($f)) continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) continue;

            $orig = (int) filesize($f);
            $orig_total += $orig;

            $best = $orig; // 配信される最小サイズ（無ければ元画像）
            foreach ($formats as $fmt) {
                $key       = md5($f . '|' . $fmt);
                $threshold = max((int) filemtime($f), self::quality_stamp($fmt));

                // 以前のエンコードで肥大化が判明していて、元画像も品質も変わっていなければ再挑戦しない
                if (isset($oversized[$key]) && (int)$oversized[$key] >= $threshold) {
                    $counts['skipped']++;
                    continue;
                }

                $q = $fmt === 'avif' ? (int)$this->opt('avif_quality', 50) : (int)$this->opt('webp_quality', 78);
                $r = $this->convert_one($f, $fmt, $q);

                if ($r === 'error') {
                    $errors[] = wp_basename($f) . ' → ' . strtoupper($fmt) . ': '
                              . ($this->last_error !== '' ? $this->last_error : '不明なエラー');
                }

                $vp = self::variant_path($f, $fmt);
                if ($r === 'converted' && file_exists($vp)) {
                    clearstatcache(true, $vp);
                    if ((int) filesize($vp) >= $orig) {
                        // 元より大きい → 配信する意味がないので削除し、記録しておく
                        @unlink($vp);
                        $oversized[$key] = time();
                        $oversized_dirty = true;
                        $r = 'skipped';
                    } elseif (isset($oversized[$key])) {
                        unset($oversized[$key]);
                        $oversized_dirty = true;
                    }
                }
                $counts[$r]++;

                if (file_exists($vp)) {
                    $best = min($best, (int) filesize($vp));
                    $present_map[$fmt] = true;
                }
            }
            $opt_total += $best;
        }

        // エラーを一覧に記録（エラーが無ければ既存エントリを解消済みとして削除）
        $this->log_errors($attachment_id, $errors);

        if ($oversized_dirty) {
            if (empty($oversized)) {
                delete_post_meta($attachment_id, self::META_OVERSIZE);
            } else {
                update_post_meta($attachment_id, self::META_OVERSIZE, $oversized);
            }
        }

        // どのフォーマットが実際に存在するか
        // （処理対象サイズのいずれか、または無効化フォーマットの既存フルサイズ派生で判定。
        //   フルサイズだけ肥大化してもサムネイル分の削減を取りこぼさないように）
        $present = [];
        $full = get_attached_file($attachment_id);
        foreach (['avif', 'webp'] as $fmt) {
            if (!empty($present_map[$fmt])
                || ($full && file_exists(self::variant_path($full, $fmt)))) {
                $present[] = $fmt;
            }
        }

        $saved_delta = 0;
        if ($orig_total > 0) {
            if (!empty($present)) {
                $saved_delta = $this->record_savings($attachment_id, $orig_total, $opt_total, $present);
            } elseif (get_post_meta($attachment_id, self::META_DONE, true)) {
                // 派生ファイルが1つも残っていない（品質変更後の再変換で全て肥大化→削除等）
                // → 過去に記録した削減量を累計から取り消し、メタも掃除する
                $old_saved = (int) get_post_meta($attachment_id, self::META_SAVED, true);
                if ($old_saved > 0) {
                    $this->add_total_saved(-$old_saved);
                    $saved_delta = -$old_saved;
                }
                delete_post_meta($attachment_id, self::META_ORIG);
                delete_post_meta($attachment_id, self::META_OPT);
                delete_post_meta($attachment_id, self::META_SAVED);
                delete_post_meta($attachment_id, self::META_FMT);
                delete_post_meta($attachment_id, self::META_DONE);
            }
        }

        $counts['saved_delta'] = $saved_delta;
        return $counts;
    }

    /**
     * 添付ごとの変換エラーを記録する。
     * エラーが空なら既存エントリを削除（再試行で解消したケース）。
     * 一覧は最大100件で、古いものから捨てる。
     */
    private function log_errors($attachment_id, array $errors) {
        $log = get_option(self::OPT_ERRORS, []);
        if (!is_array($log)) $log = [];
        $attachment_id = (int) $attachment_id;

        if (empty($errors)) {
            if (isset($log[$attachment_id])) {
                unset($log[$attachment_id]);
                update_option(self::OPT_ERRORS, $log, false);
            }
            return;
        }

        unset($log[$attachment_id]); // 再挿入して最新エントリを末尾に置く
        $log[$attachment_id] = [
            'time' => time(),
            'msgs' => array_slice(array_values(array_unique($errors)), 0, 5),
        ];
        while (count($log) > 100) {
            reset($log);
            unset($log[key($log)]);
        }
        update_option(self::OPT_ERRORS, $log, false);
    }

    /** 削減量を保存し、グローバル累計を増減。戻り値は今回の増減バイト数。 */
    private function record_savings($id, $orig, $opt, $formats) {
        $old_saved = (int) get_post_meta($id, self::META_SAVED, true);
        $new_saved = max(0, $orig - $opt);

        update_post_meta($id, self::META_ORIG,  $orig);
        update_post_meta($id, self::META_OPT,   $opt);
        update_post_meta($id, self::META_SAVED, $new_saved);
        update_post_meta($id, self::META_FMT,   $formats);
        update_post_meta($id, self::META_DONE,  time());

        $delta = $new_saved - $old_saved;
        $this->add_total_saved($delta);

        return $delta;
    }

    /**
     * 累計削減量をアトミックに増減する。
     * get_option → 加算 → update_option だと同時実行（自動変換の並列アップロードや
     * 複数管理者の同時バッチ）で加算が消失するため、SQLで直接インクリメントする。
     */
    private function add_total_saved($delta) {
        global $wpdb;
        $delta = (int) $delta;
        if (0 === $delta) return;

        // オプション行を確実に存在させる（autoload=false）
        add_option(self::OPT_TOTAL, 0, '', false);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
                SET option_value = GREATEST(0, CAST(option_value AS SIGNED) + %d)
              WHERE option_name = %s",
            $delta,
            self::OPT_TOTAL
        ));

        // キャッシュを破棄して次回の get_option で最新値を読ませる
        wp_cache_delete(self::OPT_TOTAL, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /* ===================== 添付削除時のクリーンアップ ===================== */

    /**
     * 添付ファイル削除時に .avif/.webp 派生ファイルを削除し、
     * この添付分の削減量を累計から減算する。
     * delete_attachment は実ファイル削除前に発火するため、メタ情報がまだ参照できる。
     */
    public function on_delete_attachment($attachment_id) {
        foreach (self::attachment_files($attachment_id) as $f) {
            foreach (['avif', 'webp'] as $fmt) {
                $v = self::variant_path($f, $fmt);
                if (file_exists($v)) @unlink($v);
            }
        }
        $saved = (int) get_post_meta($attachment_id, self::META_SAVED, true);
        if ($saved > 0) {
            $this->add_total_saved(-$saved);
        }
        // エラー一覧からも除去
        $this->log_errors((int) $attachment_id, []);
        // postmeta は WP が添付削除時に自動で消すため、ここでの削除は不要
    }

    /* ===================== バックグラウンド処理（WP-Cron / Action Scheduler） ===================== */

    /**
     * 非同期ジョブをキューに入れる。
     * Action Scheduler（WooCommerce等が同梱）があれば優先利用し、無ければ WP-Cron。
     */
    private static function queue_async($hook, $args = []) {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, $args, 'simple-avif-converter');
            return;
        }
        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event(time(), $hook, $args);
        }
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /** 遅延実行のスケジュール（ロック競合時の再試行用） */
    private static function queue_delayed($hook, $delay) {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, $hook, [], 'simple-avif-converter');
            return;
        }
        if (!wp_next_scheduled($hook)) {
            wp_schedule_single_event(time() + $delay, $hook);
        }
    }

    /* ===================== アップロード時の自動変換 ===================== */

    public function on_generate_metadata($metadata, $attachment_id) {
        if (!$this->opt('auto_convert', false)) return $metadata;
        if (empty($this->enabled_formats())) return $metadata;
        $mime = get_post_mime_type($attachment_id);
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            // 同期変換は大きい画像でアップロードがタイムアウトするため、
            // キューに入れてバックグラウンドで変換する
            self::queue_async(self::CRON_SINGLE, [(int) $attachment_id]);
        }
        return $metadata;
    }

    /** キューから呼ばれる: 添付1件の変換 */
    public function cron_convert_single($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $formats = $this->enabled_formats();
        if (empty($formats)) return;
        $mime = get_post_mime_type($attachment_id);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return;
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $this->convert_attachment($attachment_id, $formats);
    }

    /** キューから呼ばれる: バックグラウンド一括変換の1バッチ */
    public function cron_process_batch() {
        $state = get_option(self::OPT_BG, []);
        if (empty($state['status']) || $state['status'] !== 'running') return;

        // 多重実行防止（Action Scheduler と WP-Cron の重複起動等）
        if (get_transient(self::LOCK_BG)) {
            self::queue_delayed(self::CRON_BATCH, 30);
            return;
        }
        set_transient(self::LOCK_BG, 1, 120);

        $formats = $this->enabled_formats();
        if (empty($formats)) {
            $state['status']   = 'cancelled';
            $state['finished'] = time();
            update_option(self::OPT_BG, $state, false);
            delete_transient(self::LOCK_BG);
            return;
        }

        if (function_exists('set_time_limit')) @set_time_limit(0);
        $batch = max(1, min(20, (int) $this->opt('batch_size', 3)));

        $ids = get_posts(array_merge(self::query_args(), [
            'posts_per_page'         => $batch,
            'offset'                 => (int) $state['offset'],
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]));

        foreach ($ids as $id) {
            $r = $this->convert_attachment($id, $formats);
            $state['converted']   += $r['converted'];
            $state['skipped']     += $r['skipped'];
            $state['error']       += $r['error'];
            $state['saved_delta'] += $r['saved_delta'];
        }

        $state['offset']  += count($ids) > 0 ? count($ids) : $batch;
        $state['updated']  = time();
        if ($state['offset'] >= $state['total']) {
            $state['status']   = 'done';
            $state['finished'] = time();
        }

        // バッチ処理中に管理画面からキャンセルされていたら、進行状態を巻き戻さない
        wp_cache_delete(self::OPT_BG, 'options');
        $fresh = get_option(self::OPT_BG, []);
        if (empty($fresh['status']) || $fresh['status'] !== 'running') {
            delete_transient(self::LOCK_BG);
            return;
        }

        update_option(self::OPT_BG, $state, false);
        delete_transient(self::LOCK_BG);

        if ($state['status'] === 'running') {
            self::queue_async(self::CRON_BATCH);
        }
    }

    /* ===================== メディア一覧の列 ===================== */

    public function media_column_header($cols) {
        $cols['sac_status'] = 'AVIF / WebP';
        return $cols;
    }

    public function media_column_content($column, $id) {
        if ($column !== 'sac_status') return;
        echo $this->status_html($id); // status_html 内でエスケープ済み
    }

    public function attachment_field($form_fields, $post) {
        $form_fields['sac_status'] = [
            'label' => 'AVIF / WebP',
            'input' => 'html',
            'html'  => $this->status_html($post->ID),
        ];
        return $form_fields;
    }

    /** 添付の変換状態HTML（列・詳細で共用） */
    private function status_html($id) {
        $mime = get_post_mime_type($id);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return '<span style="color:#999;">—</span>';
        }
        if (!get_post_meta($id, self::META_DONE, true)) {
            return '<span style="color:#999;">未変換</span>';
        }
        $formats = (array) get_post_meta($id, self::META_FMT, true);
        $orig  = (int) get_post_meta($id, self::META_ORIG, true);
        $saved = (int) get_post_meta($id, self::META_SAVED, true);
        $pct   = $orig > 0 ? (int) round($saved / $orig * 100) : 0;

        $badges = '';
        foreach ($formats as $f) {
            $color = $f === 'avif' ? '#7c3aed' : '#2271b1';
            $badges .= '<span style="display:inline-block;background:' . $color . ';color:#fff;font-size:10px;'
                     . 'padding:1px 6px;border-radius:3px;margin-right:4px;">' . esc_html(strtoupper($f)) . '</span>';
        }
        $html  = '<div style="line-height:1.7;">' . $badges . '</div>';
        if ($saved > 0) {
            $html .= '<div style="color:#1a7f37;font-weight:600;">▼ ' . esc_html(size_format($saved, 1))
                   . ' 削減（' . $pct . '%）</div>';
        } else {
            $html .= '<div style="color:#999;">削減なし</div>';
        }
        return $html;
    }

    /* ===================== 管理メニュー ===================== */

    public function add_menu() {
        add_menu_page(
            'AVIF / WebP 変換',
            'AVIF / WebP',
            'manage_options',
            'simple-avif-converter',
            [$this, 'render_page'],
            'dashicons-images-alt2',
            80
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) return;

        $avif_ok = self::can_avif();
        $webp_ok = self::can_webp();
        $total   = (int) get_option(self::OPT_TOTAL, 0);
        ?>
        <div class="wrap">
            <h1>Simple AVIF / WebP Converter</h1>

            <div class="notice notice-info" style="display:flex;align-items:center;gap:24px;">
                <p style="font-size:14px;margin:10px 0;">
                    これまでの累計削減容量：
                    <strong style="font-size:20px;color:#1a7f37;"><?php echo esc_html(size_format($total, 2)); ?></strong>
                </p>
            </div>

            <p>
                AVIF対応: <strong style="color:<?php echo $avif_ok ? '#1a7f37' : '#b32d2e'; ?>;">
                    <?php echo $avif_ok ? '利用可能' : '利用不可'; ?></strong>　/　
                WebP対応: <strong style="color:<?php echo $webp_ok ? '#1a7f37' : '#b32d2e'; ?>;">
                    <?php echo $webp_ok ? '利用可能' : '利用不可'; ?></strong>
            </p>

            <h2 class="title">設定</h2>
            <form method="post" action="options.php">
                <?php settings_fields('sac_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">変換フォーマット</th>
                        <td>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[enable_avif]" value="1"
                                    <?php checked($this->opt('enable_avif')); disabled(!$avif_ok); ?>>
                                AVIF <?php echo $avif_ok ? '' : '（このサーバーでは利用不可）'; ?>
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[enable_webp]" value="1"
                                    <?php checked($this->opt('enable_webp')); disabled(!$webp_ok); ?>>
                                WebP <?php echo $webp_ok ? '' : '（このサーバーでは利用不可）'; ?>
                            </label>
                            <p class="description">両方ONにすると、対応ブラウザにAVIF、それ以外にWebP、さらに古い環境には元画像を配信します。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sac_aq">AVIF品質</label></th>
                        <td>
                            <input type="number" id="sac_aq" name="<?php echo esc_attr(self::OPT); ?>[avif_quality]"
                                   value="<?php echo esc_attr($this->opt('avif_quality')); ?>" min="1" max="100" class="small-text">
                            <span class="description">推奨 40〜55（変更すると次回の一括変換で再変換されます）</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sac_wq">WebP品質</label></th>
                        <td>
                            <input type="number" id="sac_wq" name="<?php echo esc_attr(self::OPT); ?>[webp_quality]"
                                   value="<?php echo esc_attr($this->opt('webp_quality')); ?>" min="1" max="100" class="small-text">
                            <span class="description">推奨 70〜82（変更すると次回の一括変換で再変換されます）</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sac_batch">バッチ件数</label></th>
                        <td>
                            <input type="number" id="sac_batch" name="<?php echo esc_attr(self::OPT); ?>[batch_size]"
                                   value="<?php echo esc_attr($this->opt('batch_size')); ?>" min="1" max="20" class="small-text">
                            <p class="description">一度に処理する添付件数。タイムアウト（30秒）が出る場合は減らしてください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">自動変換</th>
                        <td><label>
                            <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[auto_convert]" value="1"
                                <?php checked($this->opt('auto_convert')); ?>>
                            新規アップロード時に自動で変換する
                        </label></td>
                    </tr>
                    <tr>
                        <th scope="row">フロント配信</th>
                        <td><label>
                            <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[frontend_rewrite]" value="1"
                                <?php checked($this->opt('frontend_rewrite')); ?>>
                            対応ブラウザに &lt;picture&gt; でAVIF/WebPを配信する
                        </label>
                        <p class="description">AVIF → WebP → 元画像（JPEG/PNG）の多段フォールバックです。古いSafari等のAVIF非対応ブラウザにはWebPまたは元画像が配信されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Lazyload</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[lazyload]" value="1"
                                    <?php checked($this->opt('lazyload')); ?>>
                                すべての画像に loading="lazy"（遅延読み込み）を付与する
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <strong>注意:</strong> 他のプラグイン・テーマ・サーバー機能（LiteSpeed Cache、Jetpack、CDNの画像最適化等）の
                                lazyloadと二重に適用すると<strong>画像が表示されなくなる</strong>ことがあります。
                                lazyload機能はいずれか1つでのみ有効にしてください。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">LCP画像の優先読み込み</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[lcp_boost]" value="1"
                                    <?php checked($this->opt('lcp_boost')); ?>>
                                ファーストビューの画像をlazyloadから除外し、優先読み込みする
                            </label>
                            <p style="margin-top:6px;">
                                <label for="sac_lcp_count">対象にする画像数（ページ先頭から）: </label>
                                <input type="number" id="sac_lcp_count" name="<?php echo esc_attr(self::OPT); ?>[lcp_count]"
                                       value="<?php echo esc_attr($this->opt('lcp_count')); ?>" min="1" max="10" class="small-text">
                            </p>
                            <p class="description">
                                先頭の画像から loading="lazy" を外して fetchpriority="high" を付与します。
                                投稿・固定ページではアイキャッチ画像を &lt;link rel="preload"&gt; で先読みします
                                （テーマがアイキャッチをファーストビューに表示しない場合はOFF推奨）。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">画像サイズ属性の補完</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[add_dimensions]" value="1"
                                    <?php checked($this->opt('add_dimensions')); ?>>
                                width / height 属性が無い画像に自動で付与する（CLS対策）
                            </label>
                            <p class="description">
                                サイズ属性が無い画像はレイアウトシフト（CLS）の原因になります。
                                テーマやページビルダーが意図的にサイズ属性を省略している場合はOFFのままにしてください。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">メモリガード</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[memory_guard]" value="1"
                                    <?php checked($this->opt('memory_guard')); ?>>
                                巨大画像の変換前にメモリ使用量を見積もり、不足しそうな場合はスキップする
                            </label>
                            <p class="description">
                                共有サーバーでのメモリ不足による致命的エラー（画面が真っ白になる現象）を防ぎます。
                                スキップされた画像は下の「変換エラー一覧」に記録されます。
                                GDエンジンでの変換時のみ動作します（Imagickは自動でディスクに退避するため対象外）。
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('設定を保存'); ?>
            </form>

            <hr>

            <h2 class="title">既存画像を一括変換</h2>
            <p>メディアライブラリのJPEG/PNGをまとめて変換します。元画像は削除されません。</p>

            <p>
                <button type="button" class="button button-primary" id="sac-start"
                    <?php disabled(!$avif_ok && !$webp_ok); ?>>変換を開始</button>
                <button type="button" class="button" id="sac-stop" style="display:none;">停止</button>
            </p>

            <div id="sac-progress-wrap" style="display:none; max-width:640px;">
                <div style="background:#e2e4e7; border-radius:4px; overflow:hidden; height:24px;">
                    <div id="sac-bar" style="background:#2271b1; height:100%; width:0; transition:width .2s;"></div>
                </div>
                <p id="sac-status" style="margin-top:8px;"></p>
                <p id="sac-saved" style="margin-top:4px;font-weight:600;color:#1a7f37;"></p>
            </div>

            <hr>

            <h2 class="title">バックグラウンドで一括変換</h2>
            <p>
                サーバー側（<?php echo function_exists('as_enqueue_async_action') ? 'Action Scheduler' : 'WP-Cron'; ?>）で少しずつ変換します。
                <strong>ブラウザを閉じても処理は続行されます。</strong><br>
                <span class="description">※ WP-Cronはサイトへのアクセスを契機に動作するため、アクセスの少ないサイトでは進行が遅くなることがあります。この画面を開いている間は定期的に進行します。</span>
            </p>
            <p>
                <button type="button" class="button button-primary" id="sac-bg-start"
                    <?php disabled(!$avif_ok && !$webp_ok); ?>>バックグラウンド変換を開始</button>
                <button type="button" class="button" id="sac-bg-cancel" style="display:none;">キャンセル</button>
            </p>
            <div id="sac-bg-wrap" style="display:none; max-width:640px;">
                <div style="background:#e2e4e7; border-radius:4px; overflow:hidden; height:24px;">
                    <div id="sac-bg-bar" style="background:#00a32a; height:100%; width:0; transition:width .2s;"></div>
                </div>
                <p id="sac-bg-status" style="margin-top:8px;"></p>
            </div>

            <hr>

            <h2 class="title">変換エラー一覧</h2>
            <?php
            $err_log = get_option(self::OPT_ERRORS, []);
            if (empty($err_log) || !is_array($err_log)) {
                echo '<p>記録されているエラーはありません。</p>';
            } else {
                ?>
                <p>変換に失敗した画像の一覧です（最新100件まで保持）。原因を解消したら「再試行」で個別に再変換できます。</p>
                <table class="widefat striped" style="max-width:960px;" id="sac-err-table">
                    <thead>
                        <tr>
                            <th style="width:220px;">画像</th>
                            <th>エラー内容</th>
                            <th style="width:130px;">日時</th>
                            <th style="width:90px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_reverse($err_log, true) as $err_id => $err): // 新しい順
                        $err_id = (int) $err_id;
                        $title  = get_the_title($err_id);
                        $link   = get_edit_post_link($err_id);
                        $msgs   = implode(' / ', array_map('strval', (array) ($err['msgs'] ?? [])));
                    ?>
                        <tr>
                            <td>
                                <?php if ($link): ?>
                                    <a href="<?php echo esc_url($link); ?>"><?php echo esc_html($title !== '' ? $title : '#' . $err_id); ?></a>
                                <?php else: ?>
                                    #<?php echo $err_id; ?>
                                <?php endif; ?>
                            </td>
                            <td class="sac-err-msg"><?php echo esc_html($msgs); ?></td>
                            <td><?php echo esc_html(wp_date('Y-m-d H:i', (int) ($err['time'] ?? 0))); ?></td>
                            <td><button type="button" class="button sac-retry" data-id="<?php echo $err_id; ?>">再試行</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="sac-err-clear">エラー履歴をクリア</button></p>
                <?php
            }
            ?>

            <script>
            (function(){
                const ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                const nonce   = '<?php echo esc_js(wp_create_nonce('sac_nonce')); ?>';
                let total = 0, offset = 0;
                let batch = <?php echo (int)$this->opt('batch_size'); ?>;
                let stats = {converted:0, skipped:0, error:0, sessionSaved:0, totalSaved:0};
                let running = false;

                const $bar    = document.getElementById('sac-bar');
                const $status = document.getElementById('sac-status');
                const $saved  = document.getElementById('sac-saved');
                const $wrap   = document.getElementById('sac-progress-wrap');
                const $start  = document.getElementById('sac-start');
                const $stop   = document.getElementById('sac-stop');

                function humanBytes(b){
                    if (!b) return '0 B';
                    const u = ['B','KB','MB','GB','TB']; let i = 0;
                    while (b >= 1024 && i < u.length-1){ b/=1024; i++; }
                    return b.toFixed(i ? 2 : 0) + ' ' + u[i];
                }
                function post(action, data){
                    const body = new URLSearchParams(Object.assign({action, nonce}, data));
                    return fetch(ajaxurl, {method:'POST', credentials:'same-origin', body}).then(r => r.json());
                }
                function updateUI(){
                    const pct = total ? Math.round(offset/total*100) : 0;
                    $bar.style.width = pct + '%';
                    $status.textContent =
                        `${offset} / ${total} 件処理済み　|　画像生成: ${stats.converted}　スキップ: ${stats.skipped}　エラー: ${stats.error}`;
                    $saved.textContent =
                        `今回の削減: ${humanBytes(stats.sessionSaved)}　|　累計削減: ${humanBytes(stats.totalSaved)}`;
                }
                function step(){
                    if (!running) return;
                    if (offset >= total){ finish(true); return; }
                    post('sac_convert_batch', {offset, batch}).then(res => {
                        if (!res.success){ $status.textContent = 'エラー: ' + (res.data || '不明'); finish(false); return; }
                        offset = res.data.offset;
                        stats.converted += res.data.converted;
                        stats.skipped   += res.data.skipped;
                        stats.error     += res.data.error;
                        stats.sessionSaved += res.data.saved_delta;
                        stats.totalSaved = res.data.total_saved;
                        updateUI();
                        step();
                    }).catch(e => { $status.textContent = '通信エラー: ' + e.message; finish(false); });
                }
                function finish(done){
                    running = false;
                    $start.style.display = '';
                    $stop.style.display  = 'none';
                    if (done && total > 0){
                        $status.textContent =
                            `完了！　${offset} 件処理　|　画像生成: ${stats.converted}　スキップ: ${stats.skipped}　エラー: ${stats.error}`;
                    }
                }
                $start.addEventListener('click', function(){
                    $start.disabled = true;
                    $status.textContent = '対象画像を集計中...';
                    $saved.textContent = '';
                    $wrap.style.display = '';
                    post('sac_scan', {}).then(res => {
                        $start.disabled = false;
                        if (!res.success){ $status.textContent = '集計に失敗しました'; return; }
                        total = res.data.total; offset = 0;
                        stats = {converted:0, skipped:0, error:0, sessionSaved:0, totalSaved:res.data.total_saved||0};
                        if (total === 0){ $status.textContent = '変換対象のJPEG/PNGが見つかりませんでした。'; return; }
                        running = true;
                        $start.style.display = 'none';
                        $stop.style.display  = '';
                        updateUI();
                        step();
                    });
                });
                $stop.addEventListener('click', function(){
                    running = false;
                    $start.style.display = '';
                    $stop.style.display  = 'none';
                    $status.textContent = `停止しました（${offset} / ${total} 件まで処理）。`;
                });

                /* ---------- バックグラウンド一括変換 ---------- */
                const $bgStart  = document.getElementById('sac-bg-start');
                const $bgCancel = document.getElementById('sac-bg-cancel');
                const $bgWrap   = document.getElementById('sac-bg-wrap');
                const $bgBar    = document.getElementById('sac-bg-bar');
                const $bgStatus = document.getElementById('sac-bg-status');
                let bgTimer = null;

                function bgRender(state){
                    if (!state){
                        $bgWrap.style.display = 'none';
                        $bgCancel.style.display = 'none';
                        $bgStart.disabled = false;
                        return;
                    }
                    $bgWrap.style.display = '';
                    const pct = state.total ? Math.round(state.offset / state.total * 100) : 0;
                    $bgBar.style.width = Math.min(100, pct) + '%';
                    const labels = {running:'実行中', done:'完了', cancelled:'キャンセル済み'};
                    $bgStatus.textContent =
                        `${labels[state.status] || state.status}: ${Math.min(state.offset, state.total)} / ${state.total} 件　|　` +
                        `画像生成: ${state.converted}　スキップ: ${state.skipped}　エラー: ${state.error}　|　` +
                        `削減: ${humanBytes(state.saved_delta)}`;
                    $bgCancel.style.display = state.status === 'running' ? '' : 'none';
                    $bgStart.disabled = state.status === 'running';
                    if (state.status === 'running' && !bgTimer){
                        bgTimer = setTimeout(bgPoll, 4000);
                    }
                }
                function bgPoll(){
                    bgTimer = null;
                    post('sac_bg_status', {}).then(res => {
                        if (res.success) bgRender(res.data.state);
                    }).catch(() => { bgTimer = setTimeout(bgPoll, 8000); });
                }
                $bgStart.addEventListener('click', function(){
                    $bgStart.disabled = true;
                    post('sac_bg_start', {}).then(res => {
                        if (!res.success){
                            $bgStart.disabled = false;
                            $bgWrap.style.display = '';
                            $bgStatus.textContent = 'エラー: ' + (res.data || '不明');
                            return;
                        }
                        bgRender(res.data);
                    });
                });
                $bgCancel.addEventListener('click', function(){
                    post('sac_bg_cancel', {}).then(res => {
                        if (res.success) bgRender(res.data);
                    });
                });
                bgPoll(); // 画面表示時に進行状態を復元

                /* ---------- エラー一覧 ---------- */
                document.querySelectorAll('.sac-retry').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        btn.disabled = true;
                        btn.textContent = '変換中...';
                        const tr = btn.closest('tr');
                        post('sac_retry', {id: btn.dataset.id}).then(res => {
                            if (res.success && res.data.ok){
                                tr.style.background = '#edfaef';
                                tr.querySelector('.sac-err-msg').textContent = '解消しました（変換成功）';
                                btn.remove();
                            } else {
                                btn.disabled = false;
                                btn.textContent = '再試行';
                                tr.querySelector('.sac-err-msg').textContent =
                                    res.success ? res.data.msgs.join(' / ') : ('エラー: ' + (res.data || '不明'));
                            }
                        }).catch(() => {
                            btn.disabled = false;
                            btn.textContent = '再試行';
                        });
                    });
                });
                const $errClear = document.getElementById('sac-err-clear');
                if ($errClear){
                    $errClear.addEventListener('click', function(){
                        $errClear.disabled = true;
                        post('sac_clear_errors', {}).then(res => {
                            if (res.success){
                                const t = document.getElementById('sac-err-table');
                                if (t) t.remove();
                                $errClear.closest('p').outerHTML = '<p>記録されているエラーはありません。</p>';
                            } else {
                                $errClear.disabled = false;
                            }
                        }).catch(() => { $errClear.disabled = false; });
                    });
                }
            })();
            </script>
        </div>
        <?php
    }

    /* ===================== Ajax ===================== */

    private function check_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error('権限がありません', 403);
        check_ajax_referer('sac_nonce', 'nonce');
    }

    /** 対象クエリの共通引数 */
    private static function query_args() {
        return [
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'post_status'    => 'inherit',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
    }

    public function ajax_scan() {
        $this->check_ajax();

        // 全IDをtransientに保持する方式は巨大ライブラリでメモリを圧迫し、
        // 外部オブジェクトキャッシュ（Memcached等）の1MB制限で壊れることがあるため、
        // 件数だけ数えてバッチ側でオフセットクエリする方式に変更。
        $q = new WP_Query(array_merge(self::query_args(), [
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]));

        wp_send_json_success([
            'total'       => (int) $q->found_posts,
            'total_saved' => (int) get_option(self::OPT_TOTAL, 0),
        ]);
    }

    public function ajax_convert_batch() {
        $this->check_ajax();
        $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;
        $batch  = isset($_POST['batch'])  ? max(1, min(20, (int)$_POST['batch'])) : 3;

        $formats = $this->enabled_formats();
        if (empty($formats)) wp_send_json_error('有効な変換フォーマットがありません。');

        if (function_exists('set_time_limit')) @set_time_limit(0);

        // ID順で安定したオフセットクエリ（transient不使用）
        $ids = get_posts(array_merge(self::query_args(), [
            'posts_per_page'         => $batch,
            'offset'                 => $offset,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]));

        $agg = ['converted' => 0, 'skipped' => 0, 'error' => 0, 'saved_delta' => 0];
        foreach ($ids as $id) {
            $r = $this->convert_attachment($id, $formats);
            $agg['converted']   += $r['converted'];
            $agg['skipped']     += $r['skipped'];
            $agg['error']       += $r['error'];
            $agg['saved_delta'] += $r['saved_delta'];
        }

        // 取得件数が0なら（処理中に添付が削除された等）クライアント側で完了扱いになるよう
        // offset を進める
        $advance = count($ids) > 0 ? count($ids) : $batch;

        wp_send_json_success([
            'offset'      => $offset + $advance,
            'converted'   => $agg['converted'],
            'skipped'     => $agg['skipped'],
            'error'       => $agg['error'],
            'saved_delta' => $agg['saved_delta'],
            'total_saved' => (int) get_option(self::OPT_TOTAL, 0),
        ]);
    }

    /* ===================== Ajax: バックグラウンド一括変換 ===================== */

    public function ajax_bg_start() {
        $this->check_ajax();
        if (empty($this->enabled_formats())) {
            wp_send_json_error('有効な変換フォーマットがありません。');
        }
        $state = get_option(self::OPT_BG, []);
        if (!empty($state['status']) && $state['status'] === 'running') {
            wp_send_json_error('すでにバックグラウンド変換が実行中です。');
        }

        $q = new WP_Query(array_merge(self::query_args(), [
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]));
        $total = (int) $q->found_posts;
        if ($total === 0) {
            wp_send_json_error('変換対象のJPEG/PNGが見つかりませんでした。');
        }

        $state = [
            'status'      => 'running',
            'total'       => $total,
            'offset'      => 0,
            'converted'   => 0,
            'skipped'     => 0,
            'error'       => 0,
            'saved_delta' => 0,
            'started'     => time(),
            'updated'     => time(),
        ];
        update_option(self::OPT_BG, $state, false);
        self::queue_async(self::CRON_BATCH);

        wp_send_json_success($state);
    }

    public function ajax_bg_cancel() {
        $this->check_ajax();
        $state = get_option(self::OPT_BG, []);
        if (!empty($state['status']) && $state['status'] === 'running') {
            $state['status']   = 'cancelled';
            $state['finished'] = time();
            update_option(self::OPT_BG, $state, false);
        }
        wp_clear_scheduled_hook(self::CRON_BATCH);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CRON_BATCH);
        }
        wp_send_json_success($state);
    }

    public function ajax_bg_status() {
        $this->check_ajax();
        $state = get_option(self::OPT_BG, []);
        wp_send_json_success([
            'state'       => empty($state) ? null : $state,
            'total_saved' => (int) get_option(self::OPT_TOTAL, 0),
        ]);
    }

    /* ===================== Ajax: エラー一覧 ===================== */

    /** エラー一覧からの個別再試行 */
    public function ajax_retry() {
        $this->check_ajax();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id) wp_send_json_error('IDが不正です。');

        $formats = $this->enabled_formats();
        if (empty($formats)) wp_send_json_error('有効な変換フォーマットがありません。');

        $mime = get_post_mime_type($id);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            // 添付自体が削除済み等 → エントリだけ掃除する
            $this->log_errors($id, []);
            wp_send_json_success(['ok' => true, 'msgs' => []]);
        }

        if (function_exists('set_time_limit')) @set_time_limit(0);
        $this->convert_attachment($id, $formats);

        // convert_attachment 内で log_errors 済み。残っていれば失敗継続。
        $log   = get_option(self::OPT_ERRORS, []);
        $entry = is_array($log) && isset($log[$id]) ? $log[$id] : null;
        wp_send_json_success([
            'ok'   => $entry === null,
            'msgs' => $entry ? (array) $entry['msgs'] : [],
        ]);
    }

    /** エラー履歴のクリア */
    public function ajax_clear_errors() {
        $this->check_ajax();
        delete_option(self::OPT_ERRORS);
        wp_send_json_success();
    }

    /* ===================== フロント: <picture> 化 ===================== */

    private function url_to_path($url) {
        $up = wp_get_upload_dir();
        if (strpos($url, $up['baseurl']) !== 0) {
            return null;
        }
        $rel = rawurldecode(substr($url, strlen($up['baseurl'])));

        // パストラバーサル拒否（uploads ディレクトリ外の存在確認をさせない）
        // 先頭が "/" でなければ拒否（"uploads-evil" のような兄弟ディレクトリへのすり抜け防止）
        $rel = wp_normalize_path($rel);
        if ($rel === '' || $rel[0] !== '/'
            || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            return null;
        }
        return $up['basedir'] . $rel;
    }

    private function variant_url($url, $format) {
        $clean = preg_replace('/[?#].*$/', '', $url);
        $ext   = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) return null;
        $path = $this->url_to_path($clean);
        if (!$path || !file_exists($path)) return null;

        $variant = $path . '.' . $format;
        if (!file_exists($variant)) return null;

        // 旧バージョンで生成された「元画像より大きい派生ファイル」を配信しない保険
        $os = @filesize($path);
        $vs = @filesize($variant);
        if ($os !== false && $vs !== false && $vs >= $os) return null;

        return $clean . '.' . $format;
    }

    private function variant_srcset($srcset, $format) {
        $parts = array_map('trim', explode(',', $srcset));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            $seg  = preg_split('/\s+/', $p, 2);
            $url  = $seg[0];
            $desc = isset($seg[1]) ? ' ' . $seg[1] : '';
            $v = $this->variant_url($url, $format);
            if (!$v) return null;
            $out[] = $v . $desc;
        }
        return empty($out) ? null : implode(', ', $out);
    }

    private function build_source($src, $srcset, $sizes_attr, $format) {
        $srcset_val = null;
        if ($srcset) {
            $srcset_val = $this->variant_srcset($srcset, $format);
        }
        if (!$srcset_val) {
            $single = $this->variant_url($src, $format);
            if (!$single) return '';
            $srcset_val = $single;
        }
        return '<source type="image/' . $format . '" srcset="' . esc_attr($srcset_val) . '"' . $sizes_attr . '>';
    }

    public function rewrite_img_tag($img) {
        if (stripos($img, '<img') === false) return $img;
        if (!preg_match('/\ssrc=["\']([^"\']+)["\']/i', $img, $m)) return $img;
        $src = $m[1];

        $srcset = '';
        if (preg_match('/\ssrcset=["\']([^"\']+)["\']/i', $img, $sm)) $srcset = $sm[1];
        $sizes_attr = '';
        if (preg_match('/\ssizes=["\']([^"\']+)["\']/i', $img, $zm)) $sizes_attr = ' sizes="' . esc_attr($zm[1]) . '"';

        // AVIFを先（高圧縮）→ WebP の順。<img> の元画像が最終フォールバック
        $sources = '';
        foreach (['avif', 'webp'] as $fmt) {
            $sources .= $this->build_source($src, $srcset, $sizes_attr, $fmt);
        }
        if ($sources === '') return $img;

        return '<picture>' . $sources . $img . '</picture>';
    }

    /** wp_content_img_tag フィルタ入口 */
    public function filter_img_tag($img, $context = '', $attachment_id = 0) {
        return $this->process_img_tag($img, (int) $attachment_id);
    }

    /** post_thumbnail_html フィルタ入口 */
    public function filter_thumbnail_html($html, $post_id = 0, $thumbnail_id = 0) {
        if (stripos($html, '<img') === false) return $html;
        // すでに <picture> で包まれている場合は二重ラップしない
        if (stripos($html, '<picture') !== false) return $html;
        return preg_replace_callback('/<img\b[^>]*>/i', function ($mm) use ($thumbnail_id) {
            return $this->process_img_tag($mm[0], (int) $thumbnail_id);
        }, $html);
    }

    /** lazyload / LCP優先読み込み / <picture>化 をまとめて適用 */
    private function process_img_tag($img, $attachment_id = 0) {
        if (stripos($img, '<img') === false) return $img;

        if ($this->opt('add_dimensions')) {
            $img = $this->maybe_add_dimensions($img, $attachment_id);
        }

        $this->img_index++;
        $is_lcp = $this->opt('lcp_boost')
               && $this->img_index <= max(1, (int) $this->opt('lcp_count', 1));

        if ($is_lcp) {
            // LCP候補: lazyload（コアや他プラグインが付けたものも）を外し、優先読み込みを指定
            $img = preg_replace('/\sloading=["\']lazy["\']/i', '', $img);
            if (stripos($img, 'fetchpriority=') === false) {
                $img = preg_replace('/<img\b/i', '<img fetchpriority="high"', $img, 1);
            }
        } elseif ($this->opt('lazyload')) {
            if (!preg_match('/\sloading=/i', $img)) {
                $img = preg_replace('/<img\b/i', '<img loading="lazy"', $img, 1);
            }
            if (!preg_match('/\sdecoding=/i', $img)) {
                $img = preg_replace('/<img\b/i', '<img decoding="async"', $img, 1);
            }
        }

        if ($this->opt('frontend_rewrite', true)) {
            $img = $this->rewrite_img_tag($img);
        }
        return $img;
    }

    /* ===================== 画像サイズ属性の補完（CLS対策） ===================== */

    /**
     * width / height 属性の無い <img> に添付メタから寸法を付与する。
     * サイズ属性が無いとレイアウトシフト（CLS）が発生するため。
     * どちらか一方でも既にあればテーマの意図を尊重して何もしない。
     */
    private function maybe_add_dimensions($img, $attachment_id = 0) {
        if (preg_match('/\swidth=["\']/i', $img) || preg_match('/\sheight=["\']/i', $img)) {
            return $img;
        }
        if (!$attachment_id && preg_match('/wp-image-(\d+)/', $img, $m)) {
            $attachment_id = (int) $m[1];
        }
        if (!$attachment_id) return $img;

        if (!preg_match('/\ssrc=["\']([^"\']+)["\']/i', $img, $m)) return $img;
        $src = preg_replace('/[?#].*$/', '', $m[1]);

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta) return $img;

        // src がどの登録サイズ（サムネイル等）かをコア関数で照合して寸法を得る
        $dims = wp_image_src_get_dimensions($src, $meta, $attachment_id);
        if (!$dims || empty($dims[0]) || empty($dims[1])) return $img;

        return preg_replace(
            '/<img\b/i',
            '<img width="' . (int) $dims[0] . '" height="' . (int) $dims[1] . '"',
            $img,
            1
        );
    }

    /* ===================== LCP: アイキャッチの preload ===================== */

    /**
     * 投稿・固定ページのアイキャッチ画像を <link rel="preload"> で先読みする。
     * AVIF/WebP派生があれば type 付きで出力（非対応ブラウザは type を見てスキップ
     * するため無駄なダウンロードは発生しない）。
     */
    public function preload_lcp() {
        if (!is_singular()) return;
        $id = get_post_thumbnail_id();
        if (!$id) return;
        $mime = get_post_mime_type($id);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return;

        $src = wp_get_attachment_image_url($id, 'full');
        if (!$src) return;
        $srcset = wp_get_attachment_image_srcset($id, 'full');

        $href = $src;
        $set  = is_string($srcset) ? $srcset : '';
        $type = '';

        if ($this->opt('frontend_rewrite', true)) {
            foreach (['avif', 'webp'] as $fmt) {
                $vset = $set !== '' ? $this->variant_srcset($set, $fmt) : null;
                $vsrc = $this->variant_url($src, $fmt);
                if ($vset || $vsrc) {
                    $href = $vsrc ?: $src;
                    $set  = $vset ?: '';
                    $type = ' type="image/' . $fmt . '"';
                    break;
                }
            }
        }

        echo '<link rel="preload" as="image" href="' . esc_url($href) . '"'
           . ($set !== '' ? ' imagesrcset="' . esc_attr($set) . '" imagesizes="100vw"' : '')
           . $type . ' fetchpriority="high">' . "\n";
    }

    /* ===================== アンインストール ===================== */

    /**
     * アンインストール時のクリーンアップ。
     * 派生ファイル（.avif/.webp）・postmeta・オプションをすべて削除する。
     * register_uninstall_hook から呼ばれるため static。
     */
    public static function uninstall() {
        global $wpdb;

        // 派生ファイルの削除（直接SQLでID列挙し、メモリ消費を抑える）
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type = 'attachment'
                AND post_mime_type IN ('image/jpeg', 'image/png')"
        );
        foreach ($ids as $id) {
            foreach (self::attachment_files((int) $id) as $f) {
                foreach (['avif', 'webp'] as $fmt) {
                    $v = self::variant_path($f, $fmt);
                    if (file_exists($v)) @unlink($v);
                }
            }
        }

        // postmeta（'_sac_lqip' は旧バージョンのLQIP機能が残したメタの掃除）
        foreach ([self::META_DONE, self::META_FMT, self::META_ORIG,
                  self::META_OPT, self::META_SAVED, self::META_OVERSIZE,
                  '_sac_lqip'] as $key) {
            delete_post_meta_by_key($key);
        }

        // オプション
        delete_option(self::OPT);
        delete_option(self::OPT_TOTAL);
        delete_option(self::OPT_BG);
        delete_option(self::OPT_ERRORS);
        delete_option(self::STAMP_PREFIX . 'avif');
        delete_option(self::STAMP_PREFIX . 'webp');

        // スケジュール済みジョブ
        self::clear_scheduled_jobs();

        // 旧バージョンのtransient
        delete_transient('sac_queue');
        delete_transient(self::LOCK_BG);
    }

    /** スケジュール済みのバックグラウンドジョブをすべて解除する */
    public static function clear_scheduled_jobs() {
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook(self::CRON_SINGLE);
            wp_unschedule_hook(self::CRON_BATCH);
        } else {
            wp_clear_scheduled_hook(self::CRON_SINGLE);
            wp_clear_scheduled_hook(self::CRON_BATCH);
        }
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CRON_SINGLE);
            as_unschedule_all_actions(self::CRON_BATCH);
        }
    }

    /** 無効化時: ジョブを解除し、実行中の一括変換をキャンセル扱いにする */
    public static function deactivate() {
        self::clear_scheduled_jobs();
        $state = get_option(self::OPT_BG, []);
        if (!empty($state['status']) && $state['status'] === 'running') {
            $state['status']   = 'cancelled';
            $state['finished'] = time();
            update_option(self::OPT_BG, $state, false);
        }
        delete_transient(self::LOCK_BG);
    }
}

new Simple_AVIF_Converter();

register_deactivation_hook(__FILE__, ['Simple_AVIF_Converter', 'deactivate']);
register_uninstall_hook(__FILE__, ['Simple_AVIF_Converter', 'uninstall']);
