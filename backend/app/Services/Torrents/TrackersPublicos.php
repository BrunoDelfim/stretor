<?php

namespace App\Services\Torrents;

/**
 * Anunciadores (trackers) públicos usados para completar os magnets.
 *
 * Vários provedores devolvem apenas o infohash — sem anunciadores, o magnet não
 * monta a malha de peers e o WebTorrent fica dependendo só do DHT, que é
 * bloqueado em muitas redes domésticas e de hospedagem. Esta lista é o mínimo
 * comum acrescentado a todo magnet que sai do sistema.
 *
 * A lista mora numa classe própria (e não dentro do `TorrentService`) porque
 * agora é usada por todos os provedores, não só pelo antigo caminho Torznab/YTS.
 */
final class TrackersPublicos
{
    /** @var array<int, string> */
    public const LISTA = [
        'udp://tracker.opentrackr.org:1337/announce',
        'udp://open.tracker.cl:1337/announce',
        'udp://tracker.openbittorrent.com:6969/announce',
        'udp://exodus.desync.com:6969/announce',
        'udp://tracker.torrent.eu.org:451/announce',
        'https://tracker.tamersunion.org:443/announce',
    ];
}
