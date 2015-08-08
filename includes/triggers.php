<?php
/**
 * This is where the magic happens!  Just need to call ExperienceAPI::register()
 */

/**
 * This is for badge earning events
 */
WP_Experience_API::register( 'earned_badges', array(
	'hooks' => array( 'badgeos_award_achievement' ),
	'num_args' => array( 'badgeos_award_achievement' => 2 ),
	'process' => function( $hook, $args ) {

		//args parameter should return $user_id, $achievement_id, $this_trigger, $site_id, $args
		$current_user_id = get_current_user_id();
		if ( isset( $args[0] ) && is_int( $args[0] ) ) {
			$current_user_id = absint( $args[0] );
		}

		//check that it is a badge and NOT a step
		$current_achievement_id = 0;
		if ( isset( $args[1] ) && 'step' == get_post_type( $args[1] ) ) {
			return false;
		} else {
			$current_achievement_id = absint( $args[1] );
		}

		$options = get_option( 'wpxapi_settings' );
		if ( ! $options['wpxapi_badges'] ) {
			return false;
		}

		//figure out url for badge assertion.

		$uid = $current_achievement_id . '-' . get_post_time( 'U', true ) . '-' . $current_user_id;
		$assertion_url = site_url() . '/' . get_option( 'json_api_base', 'api' ) . '/badge/assertion/?uid=' . $uid;
		$issuer_url = site_url() . '/' . get_option( 'json_api_base', 'api' ) . '/badge/issuer/?uid=' . $uid;
		$badge_url = site_url() . '/' . get_option( 'json_api_base', 'api' ) . '/badge/badge_class/?uid=' . $uid;
		$current_post = get_post( $current_achievement_id );
		$image_url = wp_get_attachment_url( get_post_thumbnail_id( $current_achievement_id ) );

		$statement = array(
			'user' => $current_user_id,
			'verb' => array(
				'id' => 'http://specification.openbadges.org/xapi/verbs/earned',
				'display' => array( 'en-US' => 'earned' ),
			 ),
			'object' => array(
				'id' => get_permalink( $args[1] ),
				'definition' => array(
					'extensions' => array(
						'http://standard.openbadges.org/xapi/extensions/badgeclass' => array(
							'@id' => $badge_url,  //need to replace with badge_irl
							'image' => $image_url,
							'criteria' => get_permalink( $current_achievement_id ),
							'issuer' => $issuer_url,
						),
					),
					'name' => array( 'en-US' => get_the_title( $current_post->ID ) ),
					'description' => array( 'en-US' => $current_post->post_content ),
					'type' => 'http://activitystrea.ms/schema/1.0/badge',
				),
				'objectType' => 'Activity',
			),
			'context_raw' => array(
				'contextActivities' => array(
					'category' => array(
						array(
							'id' => 'http://specification.openbadges.org/xapi/recipe/base/0_0_2',
							'definition' => array( 'type' => 'http://id.tincanapi.com/activitytype/recipe' ),
							'objectType' => 'Activity',
						),
					)
				),
				'extensions' => array(
					'http://id.tincanapi.com/extension/browser-info' => array( 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ),
					'http://nextsoftwaresolutions.com/xapi/extensions/referer' => $_SERVER['HTTP_REFERER'],
				 ),
				 'platform' => defined( 'CTLT_PLATFORM' ) ? constant( 'CTLT_PLATFORM' ) : 'unknown'
			),
			'result_raw' => array(
					'extensions' => array(
						'http://specification.openbadges.org/xapi/extensions/badgeassertion' => array(
							'@id' => $assertion_url,
						)
					)
			),
			'timestamp_raw' => date( 'c' )
		);
		return $statement;
	}
) );

/**
 * This trigger is for page views of various kinds
 */
WP_Experience_API::register( 'page_views', array(
	'hooks' => array( 'wp' ), //yes, kinda broad, but if singular, should be ok
	'process' => function( $hook, $args ) {
		global $post;

		//only track front end for now.
		if ( is_admin() ) {
			return false;
		}

		$options = get_option( 'wpxapi_settings' );
		if ( 3 == $options['wpxapi_pages'] ) {
			return false;
		}
		if ( 2 == $options['wpxapi_pages'] ) {
			if ( ! is_singular() ) {
				return false;
			}
		}

		//need to make sure that description is working.
		$description = get_bloginfo( 'description' );
		if ( empty( $description ) ) {
			$description = 'n/a';
		}

		$statement = null;
		$statement = array(
			'verb' => array(
				'id' => 'http://id.tincanapi.com/verb/viewed',
				'display' => array( 'en-US' => 'viewed' ),
			),
			'object' => array(
				'id' => WP_Experience_API::current_page_url(),
				'definition' => array(
					'name' => array(
						'en-US' => get_the_title( absint( $post->ID ) ) . ' | ' . get_bloginfo( 'name' ),
					),
					'description' => array(
						'en-US' => $description,
					),
					'type' => 'http://activitystrea.ms/schema/1.0/page',
				)
			),
			'context_raw' => array(
				'extensions' => array(
					'http://id.tincanapi.com/extension/browser-info' => array( 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ),
					'http://nextsoftwaresolutions.com/xapi/extensions/referer' => $_SERVER['HTTP_REFERER'],
				),
				'platform' => defined( 'CTLT_PLATFORM' ) ? constant( 'CTLT_PLATFORM' ) : 'unknown'
			),
			'timestamp_raw' => date( 'c' )
		);

		$user = get_current_user_id();
		if ( empty( $user ) ) {
			if ( 1 == $options['wpxapi_guest'] ) {
				$user = array(
					'objectType' => 'Agent',
					'name' => 'Guest ' . $_SERVER['REMOTE_ADDR'],
					'mbox' => 'mailto:guest-' . $_SERVER['REMOTE_ADDR'] . '@' . preg_replace( '/http(s)?:\/\//', '', get_bloginfo( 'url' ) ),
				);
				$statement = array_merge( $statement, array( 'actor_raw' => $user ) );
			} else {
				return false;
			}
		} else {
			$statement = array_merge( $statement, array( 'user' => $user ) );
		}

		return $statement;
	}
));


/**
 * This trigger is for tracking comments
 */
WP_Experience_API::register( 'give_comments', array(
	'hooks' => array( 'comment_post' ), //yes, kinda broad, but if singular, should be ok
	'process' => function( $hook, $args ) {

		$options = get_option( 'wpxapi_settings' );
		if ( empty( $options['wpxapi_comments'] ) ) {
			return false;
		}

		$comment_id = $comment = false;
		if ( isset( $args[0] ) && is_int( $args[0] ) ) {
			$comment_id = absint( $args[0] );
			$comment = get_comment( $comment_id );

			if ( empty( $comment ) ) {
				return false;	//return false for invalid comment id!
			}
		} else {
			//since no comment_id, we return false!
			return false;
		}

		//need to make sure that description is working.
		$description = get_bloginfo( 'description' );
		if ( empty( $description ) ) {
			$description = 'n/a';
		}

		$statement = null;
		$statement = array(
			'user' => get_current_user_id(),
			'verb' => array(
				'id' => 'http://adlnet.gov/expapi/verbs/commented',
				'display' => array( 'en-US' => 'commented' )
			),
			'object' => array(
				'id' => get_comment_link( $comment_id ),
				'definition' => array(
					'name' => array(
						'en-US' => 'Comment: '.get_the_title( $comment->comment_post_ID ) . ' | ' . get_bloginfo( 'name' ),
					),
					'description' => array(
						'en-US' => $description,
					),
					'type' => 'http://activitystrea.ms/schema/1.0/comment',
				)
			),
			'context_raw' => array(
				'extensions' => array(
					'http://id.tincanapi.com/extension/browser-info' => array( 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ),
					'http://nextsoftwaresolutions.com/xapi/extensions/referer' => $_SERVER['HTTP_REFERER'],
				),
				'platform' => defined( 'CTLT_PLATFORM' ) ? constant( 'CTLT_PLATFORM' ) : 'unknown',
			),
			'timestamp_raw' => date( 'c' )
		);

		//we need to check then potentially add... in case something like empty comments... if possible.
		$comment_content = $comment->comment_content;
		if ( ! empty( $comment_content ) ) {
			$statement['result_raw']['response'] = $comment_content;
		}

		return $statement;
	}
));

/**
 * PulsePress theme specific stuff (voting and starring)
 */
WP_Experience_API::register( 'pulse_press_voting', array(
	'hooks' => array( 'pulse_press_vote_up', 'pulse_press_vote_down', 'pulse_press_vote_delete', 'pulse_press_star_add', 'pulse_press_star_delete' ),
	'num_args' => array( 'pulse_press_vote_delete' => 2 ),
	'process' => function( $hook, $args ) {
		global $post;

		//figure out post!
		$post_id = 0;
		if ( ! empty( $post ) && 'WP_Post' === get_class( $post ) ) {
			$post_id = $post->ID;
		}
		if ( isset( $args[0] ) && $args[0] > 0 ) {
			$post_id = absint( $args[0] );
		}

		//figure out options....
		$options = get_option( 'wpxapi_settings' );
		if ( ! isset( $options['wpxapi_voting'] ) || 4 == $options['wpxapi_voting'] ) {
			//if not set or do not trck, Don't track!
			return false;
		}

		$verb = $object = $statement = null;

		//need to make sure that description is working.
		$description = get_bloginfo( 'description' );
		if ( empty( $description ) ) {
			$description = 'n/a';
		}

		$object = array(
			'id' => get_permalink( $post_id ),
			'definition' => array(
				'name' => array(
					'en-US' => get_the_title( $post_id ) . ' | ' . get_bloginfo( 'name' ),
				),
				'description' => array(
					'en-US' => $description,
				),
				'type' => 'http://activitystrea.ms/schema/1.0/page',
			)
		);

		switch ( $hook ) {
			case 'pulse_press_vote_up':
				if ( ! in_array( $options['wpxapi_voting'], array( 1, 2 ) ) ) {
					return false;
				}

				$verb = array(
						'id' => 'http://id.tincanapi.com/verb/voted-up',
						'display' => array( 'en-US' => 'up voted' ),
				);
				break;
			case 'pulse_press_vote_down':
				if ( ! in_array( $options['wpxapi_voting'], array( 1, 2 ) ) ) {
					return false;
				}
				$verb = array(
					'id' => 'http://id.tincanapi.com/verb/voted-down',
					'display' => array( 'en-US' => 'down voted' ),
				);
				break;
			case 'pulse_press_vote_delete': //this was a tricky one. I just made up the verb ID!!!!!!  need to look for an unvote thingie.
				if ( ! in_array( $options['wpxapi_voting'], array( 1, 2 ) ) ) {
					return false;
				}
				$verb = array(
					//remember, I  made this one up!
					'id' => 'http://id.tincanapi.com/verb/voted-cancel',
					'display' => array( 'en-US' => 'vote canceled' ),
				);
				break;
			case 'pulse_press_star_add':
				if ( ! in_array( $options['wpxapi_voting'], array( 1, 3 ) ) ) {
					return false;
				}
				$verb = array(
						'id' => 'http://activitystrea.ms/schema/1.0/favorite',
						'display' => array( 'en-US' => 'favorited' ),
				);
				break;
			case 'pulse_press_star_delete':
				if ( ! in_array( $options['wpxapi_voting'], array( 1, 3 ) ) ) {
					return false;
				}
				$verb = array(
					'id' => 'http://activitystrea.ms/schema/1.0/unfavorite',
					'display' => array( 'en-US' => 'unfavorited' ),
				);
				break;
			default:
		}

		$statement = array(
			'user' => get_current_user_id(),
			'verb' => $verb,
			'object' => $object,
			'context_raw' => array(
				'extensions' => array(
					'http://id.tincanapi.com/extension/browser-info' => array( 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ),
					'http://nextsoftwaresolutions.com/xapi/extensions/referer' => $_SERVER['HTTP_REFERER'],
				),
				'platform' => defined( 'CTLT_PLATFORM' ) ? constant( 'CTLT_PLATFORM' ) : 'unknown',
			),
			'timestamp_raw' => date( 'c' )
		);

		return $statement;
	}
));

/**
 * This trigger is to track some specific post transitions (going to published, trashed, etc)
 */
WP_Experience_API::register( 'transition_post', array(
	'hooks' => array( 'transition_post_status' ),
	'num_args' => array( 'transition_post_status' => 3 ),
	'process' => function( $hook, $args ) {  //args in this case should be ($new_status, $old_status, $post)
		global $post;

		$current_post = null;
		$switched_post = false; //so we can keep track if we switched posts
		//put verb here cause we have to account for multiple possible verbs (trashed/authored for now)
		$verb = array( 'id' => 'http://activitystrea.ms/schema/1.0/author', 'display' => array( 'en-US' => 'authored' ) );

		//switch to post passed in via args vs global one as it's old and we are updating posts
		if ( isset( $args[2] ) && ! empty( $args[2] ) && $args[2] instanceof WP_Post ) {
			$current_post =  $args[2];
		} else {
			$current_post = $post;
		}

		//check site level settings for what to watch 3: nothing, 2: only to published, 1: to published and deleted
		$options = get_option( 'wpxapi_settings' );
		if ( 5 == $options['wpxapi_publish'] ) {
			return false;
		}

		//currently, it defaults to working with only public post_types
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! empty( $post_type_obj ) && property_exists( $post_type_obj, 'public' ) && $post_type_obj->public != 1 ) {
			return false;
		}

		if ( 4 == $options['wpxapi_publish'] ) {
			if (
				( isset( $args[0] ) && 'publish' == $args[0] ) && ( isset( $args[1] ) && 'publish' != $args[1] ) && //if going from anything (excluding publish) to publish state
				( isset( $args[2] ) && $args[2] instanceof WP_Post ) //if post exists
			) {
				//do nothing as this should ONLY take going to published to send to xAPI statement
			} else {
				return false;
			}
		}

		if ( 3 == $options['wpxapi_publish'] ) {
			if (
				( ( ( isset( $args[0] ) && 'publish' == $args[0] ) && ( isset( $args[1] ) && 'publish' != $args[1] ) ) || //if going from anything (excluding publish) to publish state
				( ( isset( $args[0] ) && 'trash' == $args[0] ) && ( isset( $args[1] ) && 'trash' != $args[1] ) ) ) && //if going from anything (excluding rash) to trash state
				( isset( $args[2] ) && $args[2] instanceof WP_Post ) //if post exists
			) {
				if ( 'trash' == $args[0] ) {
					$verb = array( 'id' => 'http://activitystrea.ms/schema/1.0/delete', 'display' => array( 'en-US' => 'deleted' ) );
				}
			} else {
				return false;
			}
		}

		if ( 2 == $options['wpxapi_publish'] ) {
			if (
				( ( isset( $args[0] ) && 'publish' == $args[0] ) || //include state changes from anything to published state, including published to published
				( ( isset( $args[0] ) && 'trash' == $args[0] ) && ( isset( $args[1] ) && 'trash' != $args[1] ) ) ) && //if going from anything (excluding rash) to trash state
				( isset( $args[2] ) && $args[2] instanceof WP_Post ) //if post exists
			) {
				if ( 'trash' == $args[0] ) {
					$verb = array( 'id' => 'http://activitystrea.ms/schema/1.0/delete', 'display' => array( 'en-US' => 'deleted' ) );
				} else if ( 'publish' == $args[0] && 'publish' == $args[1] ) {
					$verb = array( 'id' => 'http://activitystrea.ms/schema/1.0/update', 'display' => array( 'en-US' => 'updated' ) );
				}
			} else {
				return false;
			}
		}

		//capture almost anything
		if ( 1 == $options['wpxapi_publish'] ) {
			if (
				( isset( $args[2] ) && $args[2] instanceof WP_Post ) //if post exists
			) {
				if ( 'trash' == $args[0] ) {
					//if going to trash (aka new state is trash
					$verb = array( 'id' => 'http://activitystrea.ms/schema/1.0/delete', 'display' => array( 'en-US' => 'deleted' ) );
				} else if ( 'publish' == $args[0] && 'publish' == $args[1] ) {
					//if going from published to published
					$verb = array( 'id' => 'http://activitystrea.ms/schema/1.0/update', 'display' => array( 'en-US' => 'updated' ) );
				} else if ( 'publish' == $args[1] && 'publish' != $args[0] ) {
					//if going from published to something OTHER than published (aka retracted)
					$verb = array( 'id' => 'http://activitystrea.ms/schema/1.0/retract', 'display' => array( 'en-US' => 'retracted' ) );
				} else if ( 'publish' == $args[0] && 'publish' != $args[1] ) {
					//do nothing as the $verb variable is already set and initialized to authored.
				} else {
					//we matched everything we cared about so we just return false for the rest
					return false;
				}
			} else {
				return false;
			}
		}

		$statement = null;
		$statement = array(
			'user' => get_current_user_id(),
			'verb' => array(
				'id' => $verb['id'],
				'display' => $verb['display'],
			),
			'object' => array(
				'id' => get_permalink( $current_post->ID ),
				'definition' => array(
					'name' => array(
						'en-US' => (string) $current_post->post_title . ' | ' . get_bloginfo( 'name' ),
					),
					'type' => 'http://activitystrea.ms/schema/1.0/page',
				)
			),
			'context_raw' => array(
				'extensions' => array(
					'http://id.tincanapi.com/extension/browser-info' => array( 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ),
					'http://nextsoftwaresolutions.com/xapi/extensions/referer' => $_SERVER['HTTP_REFERER'],
				),
				'platform' => defined( 'CTLT_PLATFORM' ) ? constant( 'CTLT_PLATFORM' ) : 'unknown'
			),
			'timestamp_raw' => date( 'c' )
		);

		//now get description and insert if there is something
		$description = '';
		if ( ! empty( $current_post->post_excerpt ) ) {
			$description = $current_post->post_excerpt;
		} else if ( ! empty( $current_post->post_content ) ) {
			$description = $current_post->post_content;
		}
		if ( ! empty( $description ) ) {
			$statement['object']['definition']['description'] = array( 'en-US' => $description );
		}
		$result = $current_post->post_content;
		if ( ! empty( $result ) ) {
			$statement['result_raw']['response']= $result;
		}

		return $statement;
	}
));

/** STILL IN BETA!!!!!  **/
/*
ExperienceAPI::register('test_attachment', array(
	'hooks' => array('publish_post'),
	'process' => function( $hook, $args ) {

	$options = get_option('wpxapi_settings');
	if (empty($options['wpxapi_badges'])) {
		return false;
	}
	$upload_dir = wp_upload_dir();
	$statement = array(
			'user' => get_current_user_id(),
			'verb' => array(
				'id' => 'http://activitystrea.ms/schema/1.0/receive',
				'display' => array('en-US' => 'received')
			),
			'object' => array(
				'id' => 'http://activitystrea.ms/schema/1.0/badge',
				'definition' => array(
					'name' => array(
						'en-US' => 'badge'
					),
					'description' => array(
						'en-UR' => 'Represents a badge or award granted to an object (typically a person object)'
					)
				)
			),
			'context_raw' => array(
				'contextActivities' => array(
					'grouping' => array(
						array('objectType' => 'Activity',
							'id' => get_site_url(),
							'definition' => array(
								'name' => get_bloginfo('name'),
								'type' => 'http://adlnet.gov/expapi/activities/course'
							)
						)
					)
				),
				'extensions' => array(
						'http://id.tincanapi.com/extension/browser-info' => array('user_agent' => $_SERVER['HTTP_USER_AGENT'])
				)
			),
			'attachments_raw' => array(
				array(
					'usageType' => 'http://example.org/test/badge/attachment',
					'display' => array(
					   'en-US' => 'Test Badge Attachment'
					),
					'description' => array(
						'en-US' => 'Test to see how attachments work.'
					),
					'contentType' => 'image/png',
					'length' => filesize($upload_dir['basedir'].'/2014/12/Screen-Shot-2014-12-09-at-10.10.05-AM1.png'),
					'sha2' => hash_file('sha256', $upload_dir['basedir'].'/2014/12/Screen-Shot-2014-12-09-at-10.10.05-AM1.png'),
					'fileUrl' => 'http://wplti.dev.ctlt.ubc.ca/wp-content/uploads/2014/12/Screen-Shot-2014-12-09-at-10.10.05-AM1.png'
				)
			)
	);
	return $statement;
	}
));
*/
