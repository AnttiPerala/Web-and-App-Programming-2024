<?php 
/* 
Plugin Name: Youtube Video Fetcher
Description: Fetches the latest videos from a youtube channel and stores them in a database and allows the user to display them using a shortcode
Version 0.1
Author: Antti Perälä

*/

function fetch_and_store_youtube_videos(){
    global $wpdb;
    $api_key = 'redacted';
    $table_name = $wpdb->prefix . 'youtube_videos'; //results in the table_name being wp_youtube_videos
    $channels = [
        [
            'id' => 'UCYbK_tjZ2OrIZFBvU6CCMiA',
            'name' => 'Brackeys',
            'interval' => 12, //Interval in hours
            'keywords' => [] //optional keywords array. If left empty, we will get all videos from that channel.
        ],

        [
            'id' => 'UCue7TFlrt9FxXarpsl872Dg',
            'name' => 'Unreal Sensei',
            'interval' => 96, //Interval in hours
            'keywords' => [] //optionak keywords array. If left empty, we will get all videos from that channel.
        ]
    ];

    foreach ($channels as $channel) { //the individual item in each loop iteration will be called "channel"
        $current_time = current_time('mysql', true); //YYYY-MM-DD HH:MM:SS. The second argument is about the timezone (if true, it will use wordpress profile's timezone)

        //quering the database to get the last fetched time for the current channel
        $last_fetched = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(last_fetched) FROM $table_name WHERE channel_id = %s",
            $channel['id']
        ));

        //Check if it's already time to fetch new videos for this particular channel based on the interval and last fetched time
        if (strtotime($current_time) - strtotime($last_fetched) < ($channel['interval'] * HOUR_IN_SECONDS)){
            continue; //Continue causes the loop to immediately stop the current iteration and beging the next iteration of the loop
        }

        //building a massive url with the necessary components bit by bit
        $api_url = 'https://www.googleapis.com/youtube/v3/search?part=snippet&channelId=' . $channel['id'] . '&order=date&type=video&maxResults=10&key=' . $api_key;
        $response = wp_remote_get($api_url); //send the api request using a built in Wordpress function

        //check if API request was successfull
        if (is_wp_error($response)){
            continue; //if the request was not successfull, continue to the next channel
        }

        //Decode the JSON response
        $videos = json_decode(wp_remote_retrieve_body($response), true); //getting just the body of the response and the "true" part is decoding it to an associative PHP array

        //loop through each video and store its data in the database
        foreach($videos['items'] as $video){ //"items" is a key name in the response JSON, which contains the array of videos
            $title = $video['snippet']['title']; //dig into the snipped and from the snipped dig into the title
            $channel_keywords = $channel['keywords']; //get the keywords for this channel
            $keyword_match_found = empty($channel_keywords); //check if the keywords array is empty. empty() is a php function, which returns "true" if the variable passed into it was empty. So essentially we consider an empty keywords array to be a "match" meaning we want the videos

            //if the keywords array has values in it, check if any of them match a word in the video title
            foreach($channel_keywords as $keyword){
                if(stripos($title, $keyword) !== false){ //stripos() is a php function that searches for a string in another string. It is case insensitive.
                    $keyword_match_found = true; //if we find a match, set the keyword_match_found variable to true
                    break; //break out of the loop, meaning we don't need to check any more keywords
                }
            }

            if (!$keyword_match_found){
                continue; //if we didn't find a match, continue to the next video
            }

            //a match was found so lets get ready to store it in the database
            $data = [
                'video_id' => $video['id']['videoId'],
                'title' => $title,
                'description' => $video['snippet']['description'],
                'thumbnail' => $video['snippet']['thumbnails']['high']['url'],
                'channel_id' => $channel['id'],
                'channel_name' => $channel['name'],
                'last_fetched' => $current_time
            ];
            $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s']; //the format of the data we are going to insert into the database
            $wpdb->replace($table_name, $data, $format);

        }

    }

} //end fetch_and_store_youtube_videos

//Function to create the database table, if it doesnt already exists, on plugin activation

function create_video_table_in_database(){
    global $wpdb; //we need this global variable to be able to access the database
    $table_name = $wpdb->prefix . 'youtube_videos'; //results in the table_name being wp_youtube_videos
    $charset_collate = $wpdb->get_charset_collate(); //getting the charset and collate for the database

    //creating the table using SQL

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        video_id varchar(255) NOT NULL,
        title text NOT NULL,
        description text NOT NULL,
        thumbnail varchar(255) NOT NULL,
        channel_id varchar(255) NOT NULL,
        channel_name varchar(255) NOT NULL,
        last_fetched datetime DEFAULT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH. 'wp-admin/includes/upgrade.php'); //this file is included in the Wordpress core and is used to run the SQL queries
    dbDelta($sql); //great function that ensures existing data in the database is preserved 

}

//a function to display the latest videos on the front end
function display_youtube_videos(){
    global $wpdb;
    $table_name = $wpdb->prefix . 'youtube_videos';

    //Fetch the latest 30 videos from the database
    $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC LIMIT 30", OBJECT);

    //start building the HTML output
    $output = '<div class="youtube-videos">';
    foreach($results as $video){
        $output .= '<div class="video">'; //wrap each video in a div with a class of video
        $output .= '<h3>' . esc_html($video->title) .  '</h3>'; //dipslay the title of the video
        $output .= '<iframe width="560" height="315" src="https://www.youtube.com/embed/'. esc_attr($video->video_id). '" frameborder="0" allowfullscreen></iframe>'; //display the video
        $output .= '</div>';
    }
    $output.= '</div>';

    return $output;

}

//register the shortcode to display the videos
add_shortcode('display_youtube_videos', 'display_youtube_videos');


//call the table creation function on plugin activation
register_activation_hook(__FILE__, 'create_video_table_in_database');

//call the video fetching function once manually
//fetch_and_store_youtube_videos();