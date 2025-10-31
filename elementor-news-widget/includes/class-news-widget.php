<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_News_Widget extends \Elementor\Widget_Base {

    public function get_name() { return 'news_widget'; }
    public function get_title() { return __( 'News List (Google News)', 'elementor-news-widget' ); }
    public function get_icon() { return 'eicon-posts-list'; }
    public function get_categories() { return [ 'general' ]; }

    /* ---------------- Helpers ---------------- */

    private function build_feed_url( $q, $hl, $gl, $ceid ) {
        $base = 'https://news.google.com/rss/search';
        $params = ['q'=>$q,'hl'=>$hl,'gl'=>$gl,'ceid'=>$ceid];
        return esc_url_raw( add_query_arg( $params, $base ) );
    }

    private function extract_image_from_item( $item ) {
        $media = $item->get_item_tags( 'http://search.yahoo.com/mrss/', 'content' );
        if ( $media && ! empty( $media[0]['attribs']['']['url'] ) ) return esc_url_raw( $media[0]['attribs']['']['url'] );
        if ( $enc = $item->get_enclosure() ) {
            $link = $enc->get_link();
            if ( $link ) return esc_url_raw( $link );
        }
        $desc = $item->get_description();
        if ( $desc && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $desc, $m ) ) return esc_url_raw( $m[1] );
        return '';
    }

    private function time_ago_from_item( $item ) {
        $ts = $item->get_date( 'U' );
        if ( ! $ts ) return '';
        return sprintf( __( '%s ago', 'elementor-news-widget' ), human_time_diff( $ts, current_time( 'timestamp' ) ) );
    }

    private function publisher_domain( $url ) {
        $p = wp_parse_url( $url );
        return ! empty( $p['host'] ) ? $p['host'] : '';
    }

    private function favicon_url( $domain, $size = 64 ) {
        if ( ! $domain ) return '';
        return 'https://www.google.com/s2/favicons?sz='.(int)$size.'&domain=' . rawurlencode( $domain );
    }

    /**
     * AI Rewriter — converts a raw description to an inverted-pyramid dek.
     * Caches per-story using a transient to avoid duplicate billing.
     */
    private function ai_rewrite_dek( $link, $title, $source, $raw_desc ) {

        // If AI is disabled or key missing, skip.
        $enabled = (bool) get_option( 'enw_ai_enabled', false );
        $api_key = trim( get_option( 'enw_openai_api_key', '' ) );
        if ( ! $enabled || empty( $api_key ) ) {
            return $raw_desc;
        }

        // Cache key per story+text+model
        $model = get_option( 'enw_ai_model', 'gpt-4o-mini' );
        $hash  = md5( $link . '|' . $title . '|' . $raw_desc . '|' . $model );
        $ckey  = 'enw_ai_dek_' . $hash;

        $cached = get_transient( $ckey );
        if ( false !== $cached && is_string( $cached ) ) return $cached;

        // Compose prompt — inverted pyramid style, concise, neutral
        $temperature = floatval( get_option( 'enw_ai_temperature', 0.3 ) );
        $max_tokens  = intval( get_option( 'enw_ai_max_tokens', 120 ) );

        $messages = [
            [ 'role' => 'system', 'content' =>
                'You are a newsroom editor. Rewrite the given article summary into a concise, neutral "dek" using the inverted pyramid style: '
              . 'start with the most important facts (who/what/where/when/why/how), then add a key detail if space allows. '
              . 'Write 1–2 short sentences (35–55 words total). No hype, no first person, no emojis. Keep publisher names out of the dek.'
            ],
            [ 'role' => 'user', 'content' =>
                "Title: {$title}\nSource: {$source}\nOriginal summary:\n{$raw_desc}\n\nTask: Rewrite as a concise inverted-pyramid dek (1–2 sentences)."
            ],
        ];

        // Call OpenAI Chat Completions API
        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'timeout' => 20,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body'    => wp_json_encode( [
                    'model'       => $model,
                    'messages'    => $messages,
                    'temperature' => $temperature,
                    'max_tokens'  => max( 64, $max_tokens ),
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            // Fail gracefully
            return $raw_desc;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || empty( $body['choices'][0]['message']['content'] ) ) {
            return $raw_desc;
        }

        $dek = trim( wp_strip_all_tags( $body['choices'][0]['message']['content'] ) );
        // Keep it compact
        if ( strlen( $dek ) > 600 ) {
            $dek = wp_trim_words( $dek, 60, '…' );
        }

        // Cache for 1 day (change if you prefer)
        set_transient( $ckey, $dek, DAY_IN_SECONDS );

        return $dek;
    }

    /* ---------------- Controls ---------------- */

    protected function register_controls() {
        // Query
        $this->start_controls_section('query_section', [
            'label' => __( 'Google News Query', 'elementor-news-widget' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('query', [
            'label'       => __( 'Search Query', 'elementor-news-widget' ),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'national parks',
            'placeholder' => 'national parks OR "Grand Canyon" site:cnn.com',
        ]);
        $this->add_control('hl', [
            'label'   => __( 'Language (hl)', 'elementor-news-widget' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'en-US',
        ]);
        $this->add_control('gl', [
            'label'   => __( 'Region (gl)', 'elementor-news-widget' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'US',
        ]);
        $this->add_control('ceid', [
            'label'   => __( 'Edition (ceid)', 'elementor-news-widget' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'US:en',
        ]);
        $this->add_control('items_to_show', [
            'label'   => __( 'Items to Show', 'elementor-news-widget' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 8, 'min' => 1, 'max' => 30,
        ]);
        $this->add_control('cache_hours', [
            'label'   => __( 'Feed Cache (hours)', 'elementor-news-widget' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 2, 'min' => 0.25, 'max' => 24,
        ]);
        $this->end_controls_section();

        // Display
        $this->start_controls_section('display_section', [
            'label' => __( 'Display', 'elementor-news-widget' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('layout_style', [
            'label'   => __( 'Layout', 'elementor-news-widget' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'gn-compact',
            'options' => [
                'gn-compact' => __( 'Compact (Google News style)', 'elementor-news-widget' ),
                'gn-list'    => __( 'List (image right)', 'elementor-news-widget' ),
                'gn-cards'   => __( 'Cards (2-col desktop)', 'elementor-news-widget' ),
            ],
        ]);
        $this->add_control('open_new_tab', [
            'label'        => __( 'Open Links in New Tab', 'elementor-news-widget' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => 'yes',
        ]);
        $this->end_controls_section();
    }

    /* ---------------- Render ---------------- */

    protected function render() {
        $s = $this->get_settings_for_display();

        $query  = trim( $s['query'] ?? 'national parks' );
        $hl     = trim( $s['hl'] ?? 'en-US' );
        $gl     = trim( $s['gl'] ?? 'US' );
        $ceid   = trim( $s['ceid'] ?? 'US:en' );
        $limit  = max( 1, (int) ( $s['items_to_show'] ?? 8 ) );
        $hours  = (float) ( $s['cache_hours'] ?? 2 );
        $target = ( $s['open_new_tab'] ?? '' ) === 'yes' ? ' target="_blank" rel="noopener nofollow"' : '';
        $layout_class = in_array( $s['layout_style'], ['gn-compact','gn-list','gn-cards'], true ) ? $s['layout_style'] : 'gn-compact';

        $feed_url = $this->build_feed_url( $query, $hl, $gl, $ceid );
        $feed_cache_key = 'enw_gnews_' . md5( $feed_url . '|' . $limit );

        $items = get_transient( $feed_cache_key );
        if ( false === $items ) {
            include_once ABSPATH . WPINC . '/feed.php';
            add_filter( 'wp_feed_cache_transient_lifetime', function(){ return 0; } );

            $feed = fetch_feed( $feed_url );
            $items = [];
            if ( ! is_wp_error( $feed ) ) {
                $max = $feed->get_item_quantity( $limit );
                foreach ( $feed->get_items( 0, $max ) as $item ) {
                    $title = wp_strip_all_tags( $item->get_title() );
                    $link  = $item->get_link();
                    $desc  = wp_strip_all_tags( $item->get_description() );
                    $desc  = preg_replace( '/\s+/', ' ', $desc );

                    // --- AI rewrite into inverted pyramid style ---
                    $source = '';
                    $source_tags = $item->get_item_tags( '', 'source' );
                    if ( $source_tags && ! empty( $source_tags[0]['data'] ) ) {
                        $source = wp_strip_all_tags( $source_tags[0]['data'] );
                    }
                    $dek = $this->ai_rewrite_dek( $link, $title, $source, $desc );

                    $image   = $this->extract_image_from_item( $item );
                    $timeago = $this->time_ago_from_item( $item );
                    $domain  = $this->publisher_domain( $link );

                    $items[] = [
                        'title'  => $title,
                        'link'   => $link,
                        'excerpt'=> $dek,          // <— rewritten dek
                        'source' => $source,
                        'image'  => $image,
                        'timeago'=> $timeago,
                        'domain' => $domain,
                    ];
                }
            }
            set_transient( $feed_cache_key, $items, HOUR_IN_SECONDS * max(0.25,$hours) );
        }

        if ( ! $items ) { echo '<p>'.esc_html__('No news items found.','elementor-news-widget').'</p>'; return; }

        echo '<div class="news-widget '.$layout_class.'">';

        foreach ( $items as $it ) {
            $img_html = '';
            if ( ! empty( $it['image'] ) ) {
                $img_html = '<a class="nw-thumb" href="'.esc_url($it['link']).'"'.$target.'>'.
                                '<img loading="lazy" decoding="async" src="'.esc_url($it['image']).'" alt="">'
                            .'</a>';
            }

            $favicon_html = '';
            if ( ! empty( $it['domain'] ) ) {
                $favicon_html = '<img class="nw-favicon" src="'.esc_url( $this->favicon_url($it['domain']) ).'" alt="">';
            }

            echo '<article class="nw-item">';

                if ( $layout_class !== 'gn-list' && $img_html ) echo $img_html;

                echo '<div class="nw-body">';
                    echo '<div class="nw-source">'. $favicon_html . esc_html( $it['source'] ?: $it['domain'] ) .'</div>';
                    echo '<h3 class="nw-title"><a href="'.esc_url($it['link']).'"'.$target.'>'. esc_html($it['title']) .'</a></h3>';
                    if ( ! empty( $it['excerpt'] ) ) {
                        echo '<p class="nw-excerpt">'. esc_html( $it['excerpt'] ) .'</p>';
                    }
                    if ( ! empty( $it['timeago'] ) ) {
                        echo '<div class="nw-meta"><span class="nw-time">'. esc_html( $it['timeago'] ) .'</span></div>';
                    }
                echo '</div>';

                if ( $layout_class === 'gn-list' && $img_html ) echo $img_html;

            echo '</article>';
            echo '<hr class="nw-divider" />';
        }

        echo '</div>';
    }
}
