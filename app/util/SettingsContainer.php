<?php

/*
    //CLD: 2026-10-25T17:10:00+02:00: a minimal stand-in for chillerlan/php-settings-container, which app/pkgs/php-qrcode (upstream) extends (QROptions);
    //only what QROptions needs: the options given to the constructor, and reading / writing its protected properties (versionMin, versionMax) from outside;
    //the properties' own hooks validate the values; an unknown option is ignored
    //chillerlan\Settings\SettingsContainerAbstract is this class (class_alias below, class_map in config.php)
//  new QROptions([ 'eccLevel' => EccLevel::M, ... ])
//  $options->versionMin            any property, protected ones too
*/
namespace util;

abstract class SettingsContainer {

    public function __construct(iterable|null $properties = null) {
	foreach ($properties ?? [] as $name => $value)
	    $this->__set($name, $value);
    }

    public function __get(string $name): mixed {
	return property_exists($this, $name) ? $this->{$name} : null;
    }

    public function __set(string $name, mixed $value): void {
	if (property_exists($this, $name))
	    $this->{$name} = $value;
    }

    public function __isset(string $name): bool {
	return property_exists($this, $name) && isset($this->{$name});
    }
}

class_alias(SettingsContainer::class, 'chillerlan\Settings\SettingsContainerAbstract');
