<?php 
//Have a custom table in WordPress DB, and able to do Read and Write operations with it.
//Have a shortcode to display a form that accept user input, and then insert it into the custom table
// Have a shortcode to display the data from the custom table, with search functionality.

/*
* Plugin Name: Check Bandwidth
* Plugin URI:
* Description: Make Magic, in one day of coding.
* Version:
* Requires at least:
* Requires PHP: 7.4
* Author: Leroy B.
* Author URI: https://wpmudev.com/hub2/
* License:
* License URI: https://wpmudev.com/
* Update URI:
* Text Domain:
* Domain Path:
*/

add_action( 'init', 'my_init' );

function my_init() {
    maybe_create_my_table();
    add_shortcode( 'cb_bandwidth', 'my_shortcode_form' );
    add_shortcode( 'my_list', 'my_shortcode_list' );
}
/*Variables*/
// chkb_wppagesize = average page size of your site in kilobytes (KB)
// chkb_monavg_visits = monthly average number of visitors
// chkb_res_monavg = result of monthly average number of visitors
// chkb_avg_pageviews = average number of page views per visitor

function maybe_create_my_table() {
    /*create table*/
    global $wpdb;
    $table_name = $wpdb->prefix . 'chk_bandw';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        chkb_wppagesize int NOT NULL,
        chkb_monavg_visits int NOT NULL,
        chkb_res_monavg int NOT NULL,
        chkb_avg_pageviews int NOT NULL,
        chkb_avg_desc varchar(100) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'maybe_create_my_table' );
/*End of table creation*/

function my_shortcode_form() {
?>
<form action="" id="postband" method="post">
    <input type="hidden" name="cb_wppage_submit" value="submit">
    <div>
        <label for="pageSize">Page Size</label>
        <input type="number" id="cb_wppagesize" name="cb_wppagesize" placeholder="Page Size"> KB
    </div>
    <div>
        <label for="monthlyVisit">Average Monthly Visits</label>
        <input type="number" id="cb_monavg_visits" name="cb_monavg_visits" placeholder="AVG Monthly Visits"> Monthly
    </div>
    <div>
        <label for="avgPageviews">Average Pageviews</label>
        <input type="number" id="cb_avg_pageviews" name="cb_avg_pageviews" placeholder="AVG Pageviews"> Monthly
    </div>
    <div>
        <label for="website">Website</label>
        <input type="text" id="cb_avg_desc" name="cb_avg_desc" placeholder="Website"> URL
    </div>
    <div>
        <button type="submit" name="submit">Submit</button>
    </div>
</form>
<?php
    insert_data_to_my_table();
}

function my_shortcode_list() {
    $data = get_my_table_data( $page, $per_page, $orderby, $order, $search  ); 
    ?>
    <form action="" id="bandwid" method="post">
        <input type="hidden" name="cb_wppage_submit" value="submit">
        <div>
            <label for="websiteid">Website ID</label>
            <input type="number" id="cb_website" name="cb_website" placeholder="Website ID">
        </div>
        <div>
            <button type="submit" name="submit">Submit</button>
        </div>
    </form>
    <?php

    global $wpdb;    
    $chkTable = $wpdb->prefix.'chk_bandw';
    $pageid = $_POST['cb_pageid'];
    $result = $wpdb->get_results ( "SELECT * FROM $chkTable WHERE `id` = $pageid ");

    foreach( $result as $pageid ){        
        echo $pageid->chkb_res_monavg.'<br/>'.$pageid->chkb_avg_desc;
    }
}


function get_my_table_data( $page, $per_page, $orderby, $order, $search ) {
    /*********************CHECK LATER************* */
    // Set up your query parameters based on the function arguments
    $args = array(
        'post_type'      => 'post', // Change to your custom post type
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => $orderby,
        'order'          => $order,
        's'              => $search,
    );

    // Perform the query
    $query = new WP_Query( $args );

    // Check if there are any posts
    if ( $query->have_posts() ) {
        // Initialize an empty array to store your table data
        $table_data = array();

        // Loop through the posts and gather data
        while ( $query->have_posts() ) {
            $query->the_post();

            // Customize this part based on your data structure
            $post_id      = get_the_ID();
            $post_title   = get_the_title();
            $post_content = get_the_content();

            // Add the data to the array
            $table_data[] = array(
                'id'      => $post_id,
                'title'   => $post_title,
                'content' => $post_content,
            );
        }
        // Reset post data
        wp_reset_postdata();

        // Return the table data
        return $table_data;
        } else {
            // If no posts found, return an empty array
            return array();
        }    
}
//return [];
//}


function insert_data_to_my_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chk_bandw'; // table name
    
    if ( isset( $_POST['submit'] ) ){
        //monthly website visitors x average pageviews x average web page size
        $chkb_res_monavg = '';
        $cb_wppagesize = $_POST['cb_wppagesize'];
        $cb_monavg_visits = $_POST['cb_monavg_visits'];
        $cb_avg_pageviews = $_POST['cb_avg_pageviews'];
        $chkb_res_monavg = $cb_wppagesize*$cb_monavg_visits*$cb_avg_pageviews ;
        $wpdb->insert( $table_name, array(
            'chkb_wppagesize' => $_POST['cb_wppagesize'], 
            'chkb_res_monavg' => $chkb_res_monavg, 
            'chkb_monavg_visits' => $_POST['cb_monavg_visits'],
            'chkb_avg_desc' => $_POST['cb_avg_desc'], 
            'chkb_avg_pageviews' => $_POST['cb_avg_pageviews'] )
        );
    }
    /*$wpdb->insert( $table_name, $data );*/
}