--
--	Extension:DownloadBook - MediaWiki extension.
--	Copyright (C) 2020 Edward Chernenko.
--
--	This program is free software; you can redistribute it and/or modify
--	it under the terms of the GNU General Public License as published by
--	the Free Software Foundation; either version 2 of the License, or
--	(at your option) any later version.
--
--	This program is distributed in the hope that it will be useful,
--	but WITHOUT ANY WARRANTY; without even the implied warranty of
--	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
--	GNU General Public License for more details.
--

CREATE TABLE /*_*/bookrenderingtask (
	brt_id int unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
	brt_timestamp varbinary(14) NOT NULL DEFAULT '',

	-- String, e.g. "pending", "finished", "failed".
	brt_state varchar(32) NOT NULL,

	-- Human-readable filename that makes sense for output file, e.g. "Name_of_article.pdf".
	-- This goes into the Content-Disposition header (affects suggested filename of "Save as").
	-- NOTE: this is NOT a path. Where the file is stored is defined by brt_stash_key field.
	brt_disposition varchar(255) DEFAULT NULL,

	-- UploadStash key to retrieve the resulting image, if any.
	brt_stash_key varchar(255) DEFAULT NULL
) /*$wgDBTableOptions*/;
