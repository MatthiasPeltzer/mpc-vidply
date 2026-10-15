#
# Table structure for table 'sys_file_reference'
# Additional fields for VTT track files
#
CREATE TABLE sys_file_reference
(
	tx_lang_code  varchar(10) DEFAULT ''         NOT NULL,
	tx_track_kind varchar(50) DEFAULT 'captions' NOT NULL,
    tx_desc_src_file int unsigned DEFAULT '0' NOT NULL
);

#
# Table structure for table 'tx_mpcvidply_media'
# Standalone media records that can be reused across the site
#
CREATE TABLE tx_mpcvidply_media (
	# Media Type
	media_type varchar(50) DEFAULT 'video' NOT NULL,

	# File (for HTML5 Video/Audio and external helpers)
	media_file int unsigned DEFAULT '0' NOT NULL,

	# SEO-friendly slug for detail page routing
	slug varchar(2048) DEFAULT '' NOT NULL,

	# Metadata
	title varchar(255) DEFAULT '' NOT NULL,
	artist varchar(255) DEFAULT '' NOT NULL,
	description text,
	long_description mediumtext,
	duration int DEFAULT '0' NOT NULL,
	audio_description_duration int DEFAULT '0' NOT NULL,
	audio_description_mode varchar(20) DEFAULT 'auto' NOT NULL,
	publish_date int unsigned DEFAULT '0' NOT NULL,
	episode_number varchar(20) DEFAULT '' NOT NULL,
	
	# Poster/Thumbnail
	poster int unsigned DEFAULT '0' NOT NULL,
	
	# Accessibility features - Captions/Subtitles
	captions int unsigned DEFAULT '0' NOT NULL,
	
	# Accessibility features - Chapters
	chapters int unsigned DEFAULT '0' NOT NULL,
	
	# Accessibility features - Audio Description
	audio_description int unsigned DEFAULT '0' NOT NULL,
	
	# Accessibility features - Sign Language
	sign_language int unsigned DEFAULT '0' NOT NULL,
	sign_language_display_mode varchar(20) DEFAULT 'pip' NOT NULL,
	
	# Transcript
	enable_transcript tinyint unsigned DEFAULT '0' NOT NULL,

	# Per-media UI overrides
	hide_speed_button tinyint unsigned DEFAULT '0' NOT NULL,
	hide_help_button tinyint unsigned DEFAULT '0' NOT NULL,
	allow_download tinyint unsigned DEFAULT '0' NOT NULL,
	enable_floating_player tinyint unsigned DEFAULT '0' NOT NULL,

	# Categorization
	categories int unsigned DEFAULT '0' NOT NULL,

	KEY slug (slug(191))
);

#
# Table structure for table 'tx_mpcvidply_listview_row'
# One row per "shelf" inside a listview content element (Mediathek style)
#
CREATE TABLE tx_mpcvidply_listview_row (
	# IRRE parent relation to tt_content
	parentid int unsigned DEFAULT '0' NOT NULL,
	parenttable varchar(64) DEFAULT '' NOT NULL,
	parentfield varchar(64) DEFAULT '' NOT NULL,

	# Presentation
	headline varchar(255) DEFAULT '' NOT NULL,
	headline_link varchar(2048) DEFAULT '' NOT NULL,
	layout varchar(20) DEFAULT 'shelf' NOT NULL,
	card_style varchar(20) DEFAULT 'poster' NOT NULL,
	limit_items smallint unsigned DEFAULT '12' NOT NULL,
	enable_pagination tinyint unsigned DEFAULT '1' NOT NULL,
	pagination_per_page smallint unsigned DEFAULT '12' NOT NULL,
	sort_by varchar(50) DEFAULT 'sorting' NOT NULL,

	# Selection
	selection_mode varchar(20) DEFAULT 'manual' NOT NULL,
	media_items int unsigned DEFAULT '0' NOT NULL,
	categories int unsigned DEFAULT '0' NOT NULL,

	KEY parentid (parentid)
);

#
# Additional fields on tt_content for the listview content element
#
CREATE TABLE tt_content (
	tx_mpcvidply_listview_rows int unsigned DEFAULT '0' NOT NULL,
	tx_mpcvidply_detail_page int unsigned DEFAULT '0' NOT NULL,
	tx_mpcvidply_show_related smallint unsigned DEFAULT '1' NOT NULL,
	tx_mpcvidply_resume_playback smallint unsigned DEFAULT '0' NOT NULL,
	tx_mpcvidply_show_track_info smallint unsigned DEFAULT '0' NOT NULL,
	tx_mpcvidply_layout varchar(20) DEFAULT 'default' NOT NULL,
	tx_mpcvidply_playlist_position varchar(20) DEFAULT 'below' NOT NULL,
	tx_mpcvidply_episode_sort varchar(20) DEFAULT 'sorting' NOT NULL,
	tx_mpcvidply_episode_pagination smallint unsigned DEFAULT '1' NOT NULL,
	tx_mpcvidply_episode_per_page smallint unsigned DEFAULT '10' NOT NULL
);

#
# Table structure for table 'tx_mpcvidply_privacy_settings'
# Site-wide privacy layer configuration for external services
# Supports multilingual content via sys_language_uid
#
CREATE TABLE tx_mpcvidply_privacy_settings (
	# YouTube Settings
	youtube_headline varchar(255) DEFAULT '' NOT NULL,
	youtube_intro_text text,
	youtube_outro_text text,
	youtube_policy_link varchar(255) DEFAULT '' NOT NULL,
	youtube_link_text varchar(255) DEFAULT '' NOT NULL,
	youtube_button_label varchar(255) DEFAULT '' NOT NULL,
	
	# Vimeo Settings
	vimeo_headline varchar(255) DEFAULT '' NOT NULL,
	vimeo_intro_text text,
	vimeo_outro_text text,
	vimeo_policy_link varchar(255) DEFAULT '' NOT NULL,
	vimeo_link_text varchar(255) DEFAULT '' NOT NULL,
	vimeo_button_label varchar(255) DEFAULT '' NOT NULL,
	
	# SoundCloud Settings
	soundcloud_headline varchar(255) DEFAULT '' NOT NULL,
	soundcloud_intro_text text,
	soundcloud_outro_text text,
	soundcloud_policy_link varchar(255) DEFAULT '' NOT NULL,
	soundcloud_link_text varchar(255) DEFAULT '' NOT NULL,
	soundcloud_button_label varchar(255) DEFAULT '' NOT NULL
);
