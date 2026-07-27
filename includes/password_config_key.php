<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Llave privada para cifrar la contraseña temporal del portal
|--------------------------------------------------------------------------
| Cada instalación debe conservar una llave diferente.
| No publiques este archivo en repositorios públicos.
*/

if (!defined('PASSWORD_CONFIG_KEY')) {
    define('PASSWORD_CONFIG_KEY', '5c799e13483db38ca243555ca5025d895a934eef62fd664befcb88ff522edec0');
}
