<?php

namespace LaravelRestcord\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use LaravelRestcord\Discord;
use LaravelRestcord\Discord\Bots\HandlesBotAddedToGuild;
use LaravelRestcord\Discord\ErrorFactory;
use LaravelRestcord\Discord\Guild;

class BotCallback
{
    public function botAdded(
        Request $request,
        Application $application,
        Repository $config,
        Client $client,
        UrlGenerator $urlGenerator,
        ErrorFactory $errorFactory
    ) {
        /** @var HandlesBotAddedToGuild $botAddedHandler */
        $botAddedHandler = $application->make($config->get('laravel-restcord.bot-added-handler'));

        // can happen if the user decides not to add our bot
        if ($request->has('error')) {
            return $botAddedHandler->botNotAdded($request->get('error'));
        }

        try {
            $response = $client->post('https://discordapp.com/api/oauth2/token', [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'form_params' => [
                    'grant_type'    => 'authorization_code',
                    'client_id'     => env('DISCORD_KEY'),
                    'client_secret' => env('DISCORD_SECRET'),
                    'code'          => $request->get('code'),

                    // this endpoint is never hit, it just needs to be here for OAuth compatibility
                    'redirect_uri' => $urlGenerator->to(Discord::callbackUrl().'/bot-added'),
                ],
            ]);
        } catch (ClientException $e) {
            $json = \GuzzleHttp\json_decode($e->getResponse()->getBody()->getContents(), true);

            // Provide a more developer-friendly error message for common errors
            if (isset($json['code'])) {
                $exception = $errorFactory->make($json['code'], $json['message']);

                if ($exception != null) {
                    $e = $exception;
                }
            }

            return $botAddedHandler->errored($e);
        }

        $json = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);

        $guild = new Guild($json['guild']);

        return $botAddedHandler->botAdded($json['access_token'], $json['expires_in'], $json['refresh_token'], $guild);
    }
}
