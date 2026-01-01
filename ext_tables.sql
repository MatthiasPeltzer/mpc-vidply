#
# Table structure for table 'sys_file_reference'
# Additional fields for VTT track files
#
CREATE TABLE sys_file_reference
(
	tx_lang_code  varchar(10) DEFAULT ''         NOT NULL,
	tx_track_kind varchar(50) DEFAULT 'captions' NOT NULL,
    tx_quality_label varchar(50)         DEFAULT ''  NOT NULL,
    tx_desc_src_file int(11) unsigned DEFAULT '0' NOT NULL
);

#
# Table structure for table 'tx_mpcvidply_media'
# Standalone media records that can be reused across the site
#
CREATE TABLE tx_mpcvidply_media (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	
	# Media Type
	media_type varchar(50) DEFAULT 'video' NOT NULL,

	# HLS streams can be audio-only (radio) or video
	hls_kind varchar(10) DEFAULT 'video' NOT NULL,
	
	# URL (for YouTube, Vimeo, HLS, M3U)
	media_url text,
	
	# File (for HTML5 Video/Audio)
	media_file int(11) unsigned DEFAULT '0' NOT NULL,
	
	# Metadata
	title varchar(255) DEFAULT '' NOT NULL,
	artist varchar(255) DEFAULT '' NOT NULL,
	description text,
	duration int(11) DEFAULT '0' NOT NULL,
	audio_description_duration int(11) DEFAULT '0' NOT NULL,
	
	# Poster/Thumbnail
	poster int(11) unsigned DEFAULT '0' NOT NULL,
	
	# Accessibility features - Captions/Subtitles
	captions int(11) unsigned DEFAULT '0' NOT NULL,
	
	# Accessibility features - Chapters
	chapters int(11) unsigned DEFAULT '0' NOT NULL,
	
	# Accessibility features - Audio Description
	audio_description int(11) unsigned DEFAULT '0' NOT NULL,
	
	# Accessibility features - Sign Language
	sign_language int(11) unsigned DEFAULT '0' NOT NULL,
	
	# Transcript
	enable_transcript tinyint(4) unsigned DEFAULT '0' NOT NULL,

	# Per-media UI overrides
	hide_speed_button tinyint(4) unsigned DEFAULT '0' NOT NULL,
	
	# Standard TYPO3 fields
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
	hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,
	starttime int(11) unsigned DEFAULT '0' NOT NULL,
	endtime int(11) unsigned DEFAULT '0' NOT NULL,
	
	# Language/Translation fields
	sys_language_uid int(11) DEFAULT '0' NOT NULL,
	l10n_parent int(11) DEFAULT '0' NOT NULL,
	l10n_diffsource mediumblob,
	l10n_state text,
	
	# Categorization
	categories int(11) unsigned DEFAULT '0' NOT NULL,
	
	PRIMARY KEY (uid),
	KEY parent (pid),
	KEY language (l10n_parent,sys_language_uid)
);

#
# MM table for content element to media records relation
#
CREATE TABLE tx_mpcvidply_content_media_mm (
	uid_local int(11) unsigned DEFAULT '0' NOT NULL,
	uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
	sorting int(11) unsigned DEFAULT '0' NOT NULL,
	sorting_foreign int(11) unsigned DEFAULT '0' NOT NULL,

	KEY uid_local (uid_local),
	KEY uid_foreign (uid_foreign)
);

#
# Table structure for table 'tx_mpcvidply_privacy_settings'
# Site-wide privacy layer configuration for external services
# Supports multilingual content via sys_language_uid
#
CREATE TABLE tx_mpcvidply_privacy_settings (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	
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
	soundcloud_button_label varchar(255) DEFAULT '' NOT NULL,
	
	# Standard TYPO3 fields
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	cruser_id int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
	hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,
	
	# Language/Translation fields
	sys_language_uid int(11) DEFAULT '0' NOT NULL,
	l10n_parent int(11) DEFAULT '0' NOT NULL,
	l10n_diffsource mediumblob,
	
	PRIMARY KEY (uid),
	KEY parent (pid),
	KEY language (l10n_parent,sys_language_uid)
);

