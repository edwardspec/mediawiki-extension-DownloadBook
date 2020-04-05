<?php

/*
	Extension:DownloadBook - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Hooks used by Extension:DownloadBook.
 */

namespace MediaWiki\DownloadBook;

class Hooks {
	/**
	 * Mark username "DownloadBookStash" as reserved.
	 * @param string[] &$reservedUsernames
	 * @return bool
	 */
	public static function onUserGetReservedNames( array &$reservedUsernames ) {
		$reservedUsernames[] = 'DownloadBookStash';
		return true;
	}
}
