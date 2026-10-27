<?php
/*
    This file is part of SAPP

    Simple and Agnostic PDF Parser (SAPP) - Parse PDF documents in PHP (and update them)
    Copyright (C) 2020 - Carlos de Alfonso (caralla76@gmail.com)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace ddn\sapp\helpers;

/**
 * Function that writes a string to stderr and returns a value (to ease coding like return p_debug(...))
 * @param e the debug message
 * @param retval the value to return (default: false)
 * @return retval
 */
function p_debug($e, $retval = false) {
    \V::app()->log->debug('SAPP:' . $e);
    return $retval;
}
/**
 * Function that writes a string to stderr and returns a value (to ease coding like return p_warning(...))
 * @param e the debug message
 * @param retval the value to return (default: false)
 * @return retval
 */
function p_warning($e, $retval = false) {
    \V::app()->log->warn('SAPP:' . $e);
    return $retval;
}
/**
 * Function that writes a string to stderr and returns a value (to ease coding like return p_error(...))
 * @param e the error message
 * @param retval the value to return (default: false)
 * @return retval
 */
function p_error($e, $retval = false) {
    \V::app()->log->error('SAPP:' . $e);
    return $retval;
}
