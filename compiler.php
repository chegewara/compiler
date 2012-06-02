<?php

# Assertions:
#     - Source file exists and is a valid *.pde file
#     - Source file uses only core libraries
#     - Source file does NOT have an *.pde extension
#     - Core libraries are already compiled in build/core

# Where is this included?

function dothis($cmd, &$ret) { echo "\$ $cmd\n"; passthru($cmd, $ret); }
function dothat($cmd, &$out, &$ret)
{
	exec($cmd, $out, $ret); 
	if($ret)
		die("\$ $cmd\n ret: $ret out: $out");
}

function doit($cmd, &$out, &$ret)
{
	exec($cmd, $out, $ret); 
}


function config_output($output, $filename, &$lines, &$output_string)
{
	$output_string = "";
	$lines = array();
	foreach($output as $i)
	{
		$fat1 = "build/".$filename.":";
		$fat2 = "build/core/";
		$i = str_replace($fat1, "", $i);
		$i = str_replace($fat2, "", $i);
		
		$i = str_replace("tempfiles/".$filename.":", "", $i)."\n";
		// $i = $i."\n<br />";
		$output_string .= $i;
		$colon = strpos($i, ":");
		$number = intval(substr($i, 0, $colon));
		$j = 0;
		for($j = 0; $j < $colon ; $j++)
		{
			if(!(strpos("1234567890", $i{$j}) === FALSE))
				break;
		}
		if(!($colon === FALSE) && $j < $colon)
		{
			$lines[] = $number;
		}
		
	}
}
function do_compile($filename, $headers, &$output, &$success, &$error)
{
	$path = "tempfiles/";
	$LIBS_PATH = "../aceduino/symfony/files/libraries/";
	// Temporary: some error checking?
	// This is ugly...
	$error = 0;
	
	$filename = $path.$filename;

	// General flags. Theese are common for all projects. Should be moved to a higher-level configuration.
	// Got these from original SConstruct. Get a monkey to check them?
	$CPPFLAGS = "-ffunction-sections -fdata-sections -fno-exceptions -funsigned-char -funsigned-bitfields -fpack-struct -fshort-enums -Os";
	$LDFLAGS = "-Os -Wl,--gc-sections -lm";
	$LIBB = "";
	$LIBB .= " -I".$LIBS_PATH."EEPROM -I".$LIBS_PATH."Ethernet -I".$LIBS_PATH."Firmata -I".$LIBS_PATH."LiquidCrystal";
	$LIBB .= " -I".$LIBS_PATH."SD -I".$LIBS_PATH."SPI -I".$LIBS_PATH."Servo -I".$LIBS_PATH."SoftwareSerial -I".$LIBS_PATH."Stepper -I".$LIBS_PATH."Wire";
	
	$LIBBSOURCES = "";
	$allowed=array("o");
	foreach ($headers as $i)
	{
		$it = new RecursiveDirectoryIterator($LIBS_PATH."$i/");
		foreach(new RecursiveIteratorIterator($it) as $file) 
		{
		    if(in_array(substr($file, strrpos($file, '.') + 1),$allowed))
			{
		        // echo $file ."\n";
				$LIBBSOURCES .= "$file ";
		    }
		}
		// $LIBBSOURCES .= $LIBS_PATH."$i/$i.o ";
	}
	// $LIBBSOURCES .= $LIBS_PATH."LiquidCrystal/LiquidCrystal.o";

	// This is temporary too :(
	$CPPFLAGS .= " -Ibuild/variants/standard";

	// Append project-specific stuff.
	$CPPFLAGS .= " -mmcu=atmega328p -DARDUINO=100 -DF_CPU=16000000L";
	$LDFLAGS .= " -mmcu=atmega328p";

	// Where to places these? How to compile them?
	$SOURCES_PATH = "build/core/";
	$SOURCES = $SOURCES_PATH."wiring_shift.o ".$SOURCES_PATH."wiring_pulse.o ".$SOURCES_PATH."wiring_digital.o ".$SOURCES_PATH."wiring_analog.o ".$SOURCES_PATH."WInterrupts.o ".$SOURCES_PATH."wiring.o ".$SOURCES_PATH."Tone.o ".$SOURCES_PATH."WMath.o ".$SOURCES_PATH."HardwareSerial.o ".$SOURCES_PATH."Print.o ".$SOURCES_PATH."WString.o";

	$CLANG_FLAGS = "-fsyntax-only -Os -Iclang/include -Ibuild/variants/standard -Ibuild/core -D__AVR_ATmega328P__ -DARDUINO=100 -DF_CPU=16000000L -Wno-unknown-attributes -Wno-attributes";
	
	// Handle object files from libraries. Different CFLAGS? HELP!
	// Different error code, depending where it failed?

	dothat("./preprocess.py $filename 2>&1", $out, $ret); $error |= $ret; // *.pde -> *.cpp
	$out = "";
	$size = "";

	doit("clang $LIBB $CLANG_FLAGS $filename.cpp 2>&1", $out, $ret);
	$output = $out;
	
	doit("avr-g++ $LIBB $CPPFLAGS -c -o $filename.o $filename.cpp -Ibuild/core 2>&1", $out, $ret); // *.cpp -> *.o
	if($output == "" && $out != "")
		$output .= $out;
	$success = !$ret;
	if($success)
	{
		dothat("avr-gcc $LDFLAGS -o $filename.elf $filename.o $SOURCES $LIBBSOURCES 2>&1", $out, $ret); $error |= $ret; // *.o -> *.elf
		dothat("objcopy -O ihex -R .eeprom $filename.elf $filename.hex 2>&1", $out, $ret); $error |= $ret; // *.elf -> *.hex
		$out = "";
		dothat("avr-size --target=ihex $filename.elf 2>&1 | awk 'FNR == 2 {print $1+$2}'", $out, $ret); $error |= $ret; // We should be checking this.
		$size = $out[0];
	}
	if ($filename != $path."foo") // VERY TERMPORARY
	{
		if(file_exists($filename)) unlink($filename);	
	}
	else
	{
		if(file_exists($filename.".hex")) unlink($filename.".hex");	
	}
	if(file_exists($filename.".o")) unlink($filename.".o");	
	if(file_exists($filename.".cpp")) unlink($filename.".cpp");	
	if(file_exists($filename.".elf")) unlink($filename.".elf");	
	// Remeber to suggest a cronjob, in case something goes wrong...
	// find $path -name $filename.{o,cpp,elf,hex} -mtime +1 -delete
	return $size;
}

function parse_headers($code)
{
	$matches = "";
	$code = explode("\n", $code);
	$headers = array();
	foreach ($code as $i)
		if(preg_match('/^\s*#\s*include\s*[<"]\s*(.*)\.h\s*[>"]/', $i, $matches))
			$headers[] = $matches[1];
	return $headers;
}
?>
