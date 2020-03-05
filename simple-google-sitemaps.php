<?php
/*
Plugin Name: Simple Google Sitemaps
Plugin URI: https://lycanthropenoir.com
Description: A simple plugin, for other developers, for making weird sitemaps
Version: 0.1
Author: Jason Martin
Author URI: https://lycanthropenoir.podbean.com
Author Email: contact@lycanthropenoir.com
Text Domain: simple-google-sitemaps
*/
namespace Simple\GoogleSitemaps;
function get_sitemaps() {
    $sitemaps = ['default'];
    return apply_filters('google_sitemaps_get_sitemaps',$sitemaps);
}
function get_entries_for_sitemap($sitemap) {
    global $wpdb;
    $query = '';
    if ( $sitemap == 'default' ) {
        $where = "posts.post_status = 'publish' AND posts.post_type IN ('post','page')"; 
        $where = apply_filters("google_sitemaps_get_entries_for_{$sitemap}_where",$where);
        $query = "SELECT * FROM {$wpdb->posts} as posts WHERE $where"; 
    } else {
        $query = apply_filters("google_sitemaps_get_entries_for_{$sitemap}_query","");
    }
    if ( empty($query) ) {
        return [];
    }
    $entries = apply_filters("google_sitemaps_get_entries_custom",[],$sitemap,$query);
    if ( !empty($entries) )
        return $entries;
    $entries = $wpdb->get_results($query);
    $mapped_entries =  map_posts_to_entries(
                        apply_filters(
                            "google_sitemaps_entries_before_map", $entries));
    return $mapped_entries;
}
function map_posts_to_entries($posts) {
    return array_map(function ($p) {
        return apply_filters('google_sitemaps_map_entry',[
            'loc' => get_permalink($p),
            'lastmod' => $p->post_modified,
            'changefreq' => 'weekly',
            'priority' => 1,
        ],$p); 
    },$posts); 
}
function produce_single_sitemap_file($entries) {
    ob_start();
    echo apply_filters(
        "google_sitemaps_xml_header",
        '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
    );
    echo apply_filters(
        "google_sitemaps_xml_urlset_open",
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL
    );
    do_action("google_sitemaps_xml_before_entries",$entries);
    foreach ( $entries as $entry ) {
        $xml = '';
        foreach ( $entry as $tag=>$value ) {
            $xml .= "\t\t<$tag>$value</$tag>\n";
        }
        $xml = "\t<url>\n$xml\t</url>\n";
        echo apply_filters("google_sitemaps_xml_entry_xml",$xml);
    }
    do_action("google_sitemaps_xml_after_entries",$entries);
    echo apply_filters(
        "google_sitemaps_xml_urlset_close",
        '</urlset>' . PHP_EOL
    );
    $contents = ob_get_contents();
    ob_end_clean();
    return apply_filters('google_sitemaps_final_content',$contents);
}
function produce_sitemap() {
    $sitemaps = get_sitemaps();
    $sitemap_entries = [];
    foreach ( $sitemaps as $sitemap ) {
       $entries = get_entries_for_sitemap($sitemap);
       if ( empty($entries) )
           continue;
       $file_url = apply_filters("google_sitemaps_file_url",home_url() . "/$sitemap.xml");
       $entry = apply_filters("google_sitemaps_get_sitemap_index_entry",[
           'loc' => $file_url,
           'lastmod' => date("Y-m-d"),
       ],$sitemap);
       $sitemap_entries[$sitemap] = $entry;
       produce_single_sitemap_file($entries);
    }
    do_action('google_sitemaps_index',$sitemap_entries);
}
function template_redirect() {
     if ( !can_redirect() ) {
         return;
     }
     add_filter('google_sitemaps_get_sitemaps',function ($s) {
        return ['default'];
     });
     add_filter('google_sitemaps_final_content',function ($content) {
        echo $content;
     });
	 header('HTTP/1.0 200 ok');
     header('Content-Type: application/xml');
     produce_sitemap();
     exit;
}
add_action('template_redirect',__NAMESPACE__ . "\\template_redirect");
function can_redirect() {
    global $wp_query;
    $prot = 'http://';
    if ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ) 
        $prot = 'https://';
    $domain = '';
    if ( isset( $_SERVER['SERVER_NAME'] ) ) 
        $domain = sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) );
    $path = '';
    if ( isset( $_SERVER['REQUEST_URI'] ) )
        $path = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
    $urls = array( $prot . $domain . $path );
    if ( ! empty( $_SERVER['HTTP_HOST'] ) ) 
        $urls[] = $prot . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . $path;
    return $wp_query->is_404 && in_array( home_url( '/sitemap.xml' ), $urls, true );
}
