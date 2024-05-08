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
            'keywords' => ['unity', 'unreal'] //optionak keywords array. If left empty, we will get all videos from that channel.
        ],

        [
            'id' => 'UCue7TFlrt9FxXarpsl872Dg',
            'name' => 'Unreal Sensei',
            'interval' => 96, //Interval in hours
            'keywords' => [] //optionak keywords array. If left empty, we will get all videos from that channel.
        ]
    ]

    foreach ($channels as $channel) { //the individual item in each loop iteration will be called "channel"
        $current_time = current_time('mysql', true); //YYYY-MM-DD HH:MM:SS. The second argument is about the timezone (if true, it will use wordpress profile's timezone)

        //quering the database to get the last fetched time for the current channel
        $last_fetched = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(last_fetched) FROM $table_name WHERE channel_id = %s",
            $channel['id']
        ));

        //Check if it's already time to fetch new videos for this particular channel based on the interval and last fetched time
        if(strtotime($current_time) - strtotime($last_fetched)) < ($channel['interval'] * HOUR_IN_SECONDS)){
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
                'thumbnail' =>['snippet']['thumbnails']['high']['url'],
                'channel_id' => $channel['id'],
                'channel_name' => $channel['name'],
                'last_fetched' => $current_time
            ];
            $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s']; //the format of the data we are going to insert into the database
            $wpdb->replace($table_name, $data, $format);

        }

    }

}


'https://www.googleapis.com/youtube/v3/search?part=snippet&channelId=UCue7TFlrt9FxXarpsl872Dg&order=date&type=video&maxResults=10&key=AIzaSyBkTr1XWwgdamoiS4aMdOgnA3PISTV94Bo