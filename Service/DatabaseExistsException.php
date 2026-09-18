<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

/**
 * i-MSCP SGW_GraphQL plugin
 * Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

use RuntimeException;

/**
 * SqlServer::createDatabase() throws this, and only this, when the database
 * is already there (D3).
 *
 * The compensating drop in SqlService::createDatabase() must be armed only by
 * a CREATE this call actually performed - never by one that merely found the
 * database already present, whether that is the ordinary case or the loser of
 * a create race. A narrow, dedicated exception lets SqlService tell that case
 * apart from any other failure without inspecting a driver-specific error
 * code itself.
 */
final class DatabaseExistsException extends RuntimeException
{
}
