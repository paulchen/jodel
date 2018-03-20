#!/usr/bin/python3

import jodel_api, psycopg2, psycopg2.extras, dateutil.parser, time, os, configparser
from random import randint

def process_post(post):
    # TODO improve logging
    print(post['created_at'])

    post_id = post['post_id']
    cur = conn.cursor()
    cur.execute("""SELECT post_id FROM message WHERE post_id = %s""", (post_id, ))
    row = cur.fetchone()
    # TODO update already known posts
    if row is None:
        timestamp = dateutil.parser.parse(post['created_at'])
        from_home = ('from_home' in post)
        image_url = (post['image_url'] if ('image_url' in post) else None)
        thumbnail_url = (post['thumbnail_url'] if ('thumbnail_url' in post) else None)

        cur.execute("""INSERT INTO message (message, created_at, replier, post_id, vote_count, got_thanks, user_handle, color, post_own, distance, location_name, from_home, image_url, thumbnail_url) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""", (post['message'], timestamp, post['replier'], post['post_id'], post['vote_count'], post['got_thanks'], post['user_handle'], post['color'], post['post_own'], post['distance'], post['location']['name'], from_home, image_url, thumbnail_url))
    
    cur.close()


def get_config(key):
    cur = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
    cur.execute("""SELECT value FROM config WHERE key = %s""", (key, ))
    row = cur.fetchone()
    if row is None:
        result = None
    else:
        result = row['value']
    cur.close()
    return result


def set_config(key, value):
    cur = conn.cursor()
    cur.execute("""INSERT INTO config (key, value) VALUES (%s, %s) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value""", (key, value))
    cur.close()



config_file = os.path.dirname(os.path.realpath(__file__)) + '/../config.ini'

settings = configparser.ConfigParser()
settings.read(config_file)

# TODO run only once

# TODO don't hardcode anything
connect_string = "dbname='%s' user='%s' host='%s' password='%s' port='%s'" % (settings['general']['db_name'], settings['general']['db_user'], settings['general']['db_host'], settings['general']['db_pass'], settings['general']['db_port'])
try:
    conn = psycopg2.connect(connect_string)
except:
    logger.error('Database error')
    sys.exit(1)


# TODO don't hardcode anything
lat, lng, city = 48.208333, 16.373056, 'Vienna'
access_token = get_config('access_token')
expiration_date = get_config('expiration_date')
refresh_token = get_config('refresh_token')
device_uid = get_config('device_uid')
distinct_id = get_config('distinct_id')

j = jodel_api.JodelAccount(lat=lat, lng=lng, city=city, access_token=access_token, expiration_date=expiration_date, refresh_token=refresh_token, device_uid=device_uid, distinct_id=distinct_id, is_legacy=False, update_location=False)

# TODO check expiration_date

# TODO init skip
skip = get_config('next_post_id')
while True:
    # TODO don't hardcode post id
    if skip is None:
        data = j.get_post_details_v3('59f73d3d8165f00010071314')
    else:
        data = j.get_post_details_v3('59f73d3d8165f00010071314', skip=skip)

    if data[0] != 200:
	# TODO error handling
        break

    process_post(data[1]['details'])
    for reply in data[1]['replies']:
        process_post(reply)
   
    skip = data[1]['next']
    if skip is not None:
        set_config('next_post_id', skip)

    if data[1]['remaining'] == 0:
        break

    conn.commit()

    seconds = randint(3, 8)
    time.sleep(seconds)

conn.commit()
conn.close()

# TODO error handling, touch status file on success (for Icinga)

