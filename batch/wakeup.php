<?php

use Cmfcmf\OpenWeatherMap;
use Cmfcmf\OpenWeatherMap\Exception as OWMException;
use Cmfcmf\OpenWeatherMap\WeatherForecast;
use Dotenv\Dotenv;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Phue\Client;
use Phue\Light;
use Phue\Transport\Exception\DeviceParameterUnmodifiableException;

require_once __DIR__ . '/../vendor/autoload.php';

$dotEnv = Dotenv::create(__DIR__ . '/..');
$dotEnv->load();

$logger = new Logger('WakeUp');
$logger->pushHandler(new RotatingFileHandler('/var/log/home/wakeup'));
$logger->pushHandler(new StreamHandler('php://stderr'));
$client = new Client(getenv('HUE_IP'), getenv('HUE_USERNAME'));

/**
 * @param Client $client
 * @return Light[]
 */
function getBedroomLights(Client $client): array
{
    $bedroomGroup = null;

    foreach ($client->getGroups() as $group) {
        if ($group->getName() === 'Bedroom') {
            $bedroomGroup = $group;
            break;
        }
    }

    $lights = $client->getLights();
    $bedroomLights = [];

    foreach ($bedroomGroup->getLightIds() as $lightId) {
        if (array_key_exists($lightId, $lights)) {
            $bedroomLights[] = $lights[$lightId];
        }
    }

    return $bedroomLights;
}


try {
    $owm = new OpenWeatherMap(getenv('OWM_API_KEY'));
    $forecast = $owm->getWeatherForecast('Edinburgh', 'metric', 'gb', '', 1);
} catch(OWMException $e) {
    $logger->err($e->getMessage(), ['e' => $e]);
} catch(\Exception $e) {
    $logger->err($e->getMessage(), ['e' => $e]);
}

/**
 * @param WeatherForecast $forecast
 *
 * @return int[]
 */
function getRgbForForecast(WeatherForecast $forecast): array
{
    $forecast->rewind();
    $currentTemp = (int) $forecast->current()->temperature->getValue();

    $currentTemp = $currentTemp > -6 ? $currentTemp : -6;
    $currentTemp = $currentTemp < 15 ? $currentTemp : 15;

    $scale = (($currentTemp / 21) + 6 / 21);

    $red = (int) ((($currentTemp / 21) + 6 / 21) * 255);
    $blue = (int) ((1 - $scale) * 255);

    return [$red, 0, $blue];
}

function rainExceedsVolumeInWorkingDay(int $volume, WeatherForecast $forecast)
{
    foreach ($forecast as $weather) {
        if ($weather->time->from->format('H') >= 15) {
            return false;
        }

        if ($weather->precipitation->getValue() >= $volume) {
            return true;
        }
    }

    return false;
}

$wakeupColor = [255, 200, 200];

if (rainExceedsVolumeInWorkingDay(3, $forecast)) {
    $logger->info('Detected rain, setting wakeup color');
    $wakeupColor = [50, 50, 255];
}


$bedroomLights = getBedroomLights($client);

/**
 * @param int $period (seconds)
 * @return Generator
 */
function generateIncreasingLightLevels(int $period): Generator
{
    $microsecondSleep = (1000000 / 255) * $period;

    for ($i = 0; $i <= 255; $i++) {
        yield $i;
        usleep($microsecondSleep);
    }
}

$originalColours = [];

foreach ($bedroomLights as $light) {
    $originalColours[$light->getId()] = $light->getRGB();

    $light->setOn(true);
    if ($light->getName() === 'Drawers') {
        $rgb = getRgbForForecast($forecast);
        $light->setRGB(...$rgb);
    } else {
        $light->setRGB(...$wakeupColor);
    }
}


try {
    foreach (generateIncreasingLightLevels(1800) as $lightLevel) {
        $logger->debug('Increasing light level to {level}', ['level' => $lightLevel]);

        foreach ($bedroomLights as $light) {
            $light->setBrightness($lightLevel);
        }
    }
} catch (DeviceParameterUnmodifiableException $e) {
    $logger->notice('Failed to modify device', ['exception' => $e->__toString()]);
}

sleep(3600);

foreach ($bedroomLights as $bedroomLight) {
    if (isset($originalColours[$bedroomLight->getId()])) {
        $bedroomLight->setRGB(...$originalColours[$bedroomLight->getId()]);
    }

    $bedroomLight->setOn(false);
}
