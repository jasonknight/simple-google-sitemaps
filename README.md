# Simple Google Sitemaps

This plugin is for Developers, not end users. The default behavior of this plugin is extremely simple and almost useless.

The purpose of the plugin is for you to be able to easily define, and have fine grained control of, the urls that will appear in the sitemap.

Most sitemap plugins are static and largely useless. They just create a fancy index of your pages, that is not the purpose of an XML sitemap, including pages that haven't been updated is pointless. The XML Sitemap is to notify the google crawler of what is new and needs to be indexed.

What follows is a real world example of how to use this plugin to do something insane. The only reason I designed this was because someone paid me to do it, and I realized existing plugins sucked. So I made this to make it easier, and I'm publishing the plugin and tutorial in the hopes it will help out other devs.

## Tutorial

So any website with a sufficiently complex url structure, or special urls, is not going to be able to generate the sitemap on the fly. If you have anything more than a couple of hundred links, you're really going to have difficulty.

So the way we are going to do this is by pre-generating all of the links in SQL, and then using filters for this plugin to inject them.

First, we'll create a file like this, called sitemap_queries.sql in the main folder of the child theme (or a plugin if you want).

```sql
drop table if exists listing_regions;
create table listing_regions as (select t.term_id,t.name,t.slug,tt.parent
	from `wp_terms` as t
	join `wp_term_taxonomy` as tt ON tt.term_id = t.term_id
	where tt.taxonomy = 'job_listing_region');
drop table if exists listing_categories;
create table listing_categories as (select t.term_id,t.name,t.slug,tt.parent
	from `wp_terms` as t
	join `wp_term_taxonomy` as tt ON tt.term_id = t.term_id
	where tt.taxonomy = 'job_listing_category');
drop table if exists dynamic_links;
create table dynamic_links as (select 
	concat('/production/',r.slug,'/',c.slug) as path, 
	r.term_id as rid, 
	c.term_id as cid
	from listing_regions as r, listing_categories as c);	
create index dl_term_ids ON dynamic_links(rid,cid);
drop table if exists listing_links_tmp;
create table listing_links_tmp as (select ll.*, (
	select MAX(p.last_comment_date) from `wp_posts` as p
		join `wp_term_taxonomy` as tt on tt.term_id != 0
		join `wp_term_relationships` as tr on tt.term_taxonomy_id = tr.term_taxonomy_id
		WHERE p.ID = tr.object_id AND
		tt.term_id = ll.rid
	) as rlcd,
	(
	select MAX(p.last_comment_date) from `wp_posts` as p
		join `wp_term_taxonomy` as tt on tt.term_id != 0
		join `wp_term_relationships` as tr on tt.term_taxonomy_id = tr.term_taxonomy_id
		WHERE p.ID = tr.object_id AND
		tt.term_id = ll.cid
	) as clcd,
	'dynamic' as ltype from dynamic_links as ll);
insert into listing_links_tmp 
	select concat('/listing/',p.post_name) as path, 
		   0 as rid,
		   0 as cid,
		   p.post_modified as rlcd,
		   p.last_comment_date as clcd,
		   'listing' as ltype
		   from `wp_posts` as p
		   where
		   	p.post_type = 'job_listing' and
		   	p.post_status = 'publish';

insert into listing_links_tmp select
	concat('/production/all/', lc.slug) as path,
	0 as rid,
	lc.term_id as cid,
	null as rlcd,
	(select MAX(llt2.clcd) from listing_links_tmp as llt2 where llt2.cid = lc.term_id) as clcd,
	'dynamic' as ltype
	from
		listing_categories as lc;
insert into listing_links_tmp select
	concat('/production/', lr.slug) as path,
	lr.term_id as rid,
	0 as cid,
	(select MAX(llt2.rlcd) from listing_links_tmp as llt2 where llt2.rid = lr.term_id) as rlcd,
	null as clcd,
	'dynamic' as ltype
	from
		listing_regions as lr;
update listing_links_tmp
	set rlcd = '1976-01-01 23:55:55' where rlcd IS NULL;
update listing_links_tmp
	set clcd = '1976-01-01 23:55:55' where clcd IS NULL;
drop table if exists listing_links;
rename table listing_links_tmp to listing_links;
```

This code is just an example, if you don't have the exact listify setup I was working with, some of these queries aren't going to make any sense. You should get the basic idea.

There is also a reason why the queries are separated, and I don't try to do too much in any one query.

Now, we need the select query, it looks like this:

```sql

select path, 
	IF(rlcd > clcd,rlcd,clcd) as modified, 
	(DATE_ADD(
		STR_TO_DATE(
			IF(
				rlcd > clcd,rlcd,clcd), '%Y-%m-%d %H:%i:%s'),
				INTERVAL 24 HOUR) > NOW()) as daily,
	(DATE_ADD(
		STR_TO_DATE(
			IF(
				rlcd > clcd,rlcd,clcd), '%Y-%m-%d %H:%i:%s'),
				INTERVAL 7 DAY) > NOW()) as weekly,
	(DATE_ADD(
		STR_TO_DATE(
			IF(
				rlcd > clcd,rlcd,clcd), '%Y-%m-%d %H:%i:%s'),
				INTERVAL 30 DAY) > NOW()) as monthly,
	(DATE_ADD(
		STR_TO_DATE(
			IF(
				rlcd > clcd,rlcd,clcd), '%Y-%m-%d %H:%i:%s'),
				INTERVAL 1 YEAR) > NOW()) as yearly
	from listing_links
        where 
            (
                rlcd > DATE_SUB(NOW(), INTERVAL 30 DAY) OR 
                clcd > DATE_SUB(NOW(), INTERVAL 30 DAY)
            )
        limit 49000
    ;
```

The reason for this craziness is irrelevant, sufice it to say we needed it to be this way, and we wanted it to be this way, and it proved to be good. You don't have to do this, and you shouldn't unless that fits with what you're trying to accomplish. Here it was really important to only get links that have been recently updated, and also to have an idea about how recently updated they were. 

Now that you have these two queries, you just need a couple of filters in your functions.php file.

```php
<?php
    // you need this to generate the sitemap from a cron
    if ( isset($_GET['some-secret-thing']) ) {
        add_action('init', function () {
            do_action('generate_sitemap');
        });
    }
    function generate_sitemap() {
        global $wpdb;
        $queries = file_get_contents(__DIR__ . '/sitemap_queries.sql');
        $queries = explode(';',$queries);
        foreach ( $queries as $q ) {
            $q = trim($q);
            if ( empty($q) )
                continue;
           echo "Executing: [$q]".PHP_EOL;
           $res = $wpdb->query($q); 
           print_r($res);
        }
        exit;
    }
    add_action('generate_sitemap','generate_sitemap');
    function sitemap_get_entries_custom($entries,$s,$q) {
        global $wpdb;
        $entries = [];
        $sql = file_get_contents(__DIR__ . "/sitemap_select_entries.sql");
        $results = $wpdb->get_results($sql); 
        $base = home_url();
        foreach ( $results as $r ) {
            $priority = 1;
            $freq = 'never';
            if ( $r->daily == 1 ) {
               $freq = 'daily'; 
               $priority += 3;
            } else if ( $r->weekly == 1 ) {
               $freq = 'weekly'; 
               $priority += 2;
            } else if ( $r->monthly == 1 ) {
               $freq = 'monthly'; 
               $priority += 1;
            }
            $priority = $priority / 10;
            $date = new \DateTime($r->modified);
            $date = $date->format(\DateTime::W3C);
            $entry = apply_filters('google_sitemaps_map_entry', [
                'loc' => join('',[$base,$r->path]),
                'lastmod' => $date,
                'changefreq' => $freq,
                'priority' => $priority 
            ]);
            $entries[] = $entry;
        }
        return $entries;
    }
    add_filter('google_sitemaps_get_entries_custom','sitemap_get_entries_custom',10,3);
```

Now the last thing to do is go to the crontab -e, or CPanel CronJob section and add a cronjob that
calls wget https://yoursite.com?some-secret-action=1 to generate the sitemap.
