#!/usr/bin/python3

import jodel_api, psycopg2, psycopg2.extras, dateutil.parser, time, os, configparser, sys, fcntl, logging, re
from urllib.request import urlopen
from random import randint
from datetime import datetime


fh = 0

def run_once():
    global fh
    fh = open(os.path.realpath(__file__), 'r')
    try:
        fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except:
        logger.debug('Already running, terminating now')
        os._exit(0)


def process_post(post, jodel_fk):
    logger.debug('Processing post %s (created at %s)' % (post['post_id'], post['created_at']))

    post_id = post['post_id']
    cur = conn.cursor()
    cur.execute("""SELECT post_id FROM message WHERE post_id = %s""", (post_id, ))
    row = cur.fetchone()

    timestamp = dateutil.parser.parse(post['created_at'])
    from_home = ('from_home' in post)
    image_url = (post['image_url'] if ('image_url' in post) else None)
    thumbnail_url = (post['thumbnail_url'] if ('thumbnail_url' in post) else None)
    replier = (post['replier'] if ('replier' in post) else None)

    if row is None:
        logger.debug('Inserting new post')

        cur.execute("""INSERT INTO message (message, created_at, replier, post_id, vote_count, got_thanks, user_handle, color, post_own, distance, location_name, from_home, image_url, thumbnail_url, jodel_id) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""", (post['message'], timestamp, replier, post['post_id'], post['vote_count'], post['got_thanks'], post['user_handle'], post['color'], post['post_own'], post['distance'], post['location']['name'], from_home, image_url, thumbnail_url, jodel_fk))

    else:
        logger.debug('Update existing post')

        cur.execute("""UPDATE message SET message = %s, created_at = %s, replier = %s, vote_count = %s, got_thanks = %s, user_handle = %s, color = %s, post_own = %s, distance = %s, location_name = %s, from_home = %s, image_url = %s, thumbnail_url = %s WHERE post_id = %s""", (post['message'], timestamp, replier, post['vote_count'], post['got_thanks'], post['user_handle'], post['color'], post['post_own'], post['distance'], post['location']['name'], from_home, image_url, thumbnail_url, post['post_id']))
    
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


def init():
    lat, lng, city = settings['location']['latitude'], settings['location']['longitude'], settings['location']['name']
    access_token = get_config('access_token')
    expiration_date = get_config('expiration_date')
    refresh_token = get_config('refresh_token')
    device_uid = get_config('device_uid')
    distinct_id = get_config('distinct_id')

    tokens_changed = False
    if access_token is None and expiration_date is None and refresh_token is None and device_uid is None and distinct_id is None:
        logger.debug('Creating new jodel account: lat=%s, lng=%s, city=%s' % (lat, lng, city))

        j = jodel_api.JodelAccount(lat=lat, lng=lng, city=city)
        tokens_changed = True
      
    else:
        j = jodel_api.JodelAccount(lat=lat, lng=lng, city=city, access_token=access_token, expiration_date=expiration_date, refresh_token=refresh_token, device_uid=device_uid, distinct_id=distinct_id, is_legacy=False, update_location=False)

        if datetime.fromtimestamp(int(expiration_date)) < datetime.now():
            logger.debug('Expiration date %s has passed, refreshing access token' % (expiration_date, ))

            result = j.refresh_access_token()
            if result[0] != '200':
                logger.error('Unable to refresh access token, refresh all tokens')

                try:
                    result = j.refresh_all_tokens()
                except Exception:
                    logger.exception('Unable to refresh all tokens')
                    return None
            tokens_changed = True

    if tokens_changed:
        account_data = j.get_account_data()
        for token in ('access_token', 'expiration_date', 'refresh_token', 'device_uid', 'distinct_id'):
            set_config(token, account_data[token])

    return j




config_file = os.path.dirname(os.path.realpath(__file__)) + '/../config.ini'
logfile = os.path.dirname(os.path.realpath(__file__)) + '/../log/update.log'

settings = configparser.ConfigParser()
settings.read(config_file)

logger = logging.getLogger()
handler = logging.FileHandler(logfile)
handler.setFormatter(logging.Formatter('%(asctime)s %(name)-12s %(levelname)-8s %(message)s'))
logger.addHandler(handler)
logger.setLevel(logging.DEBUG)


def download_image(url):
    directory = get_config('download_dir')
    filename = re.sub(r"^.*\/", '', url)
    local_filename = directory + '/' + filename

    logger.debug('Downloading %s to %s...' % (url, local_filename))

    try:
        response = urlopen('https:' + url, None, 10)
    except Exception:
        logger.error('Error downloading %s' % (url, ))
        return None


    with open(local_filename, 'wb') as f:
        f.write(response.read())

    cur = conn.cursor()
    cur.execute("""INSERT INTO image (filename) VALUES (%s) RETURNING id""", (filename, ))
    inserted_id = cur.fetchone()[0]
    cur.close()

    logger.debug('Inserted Id: %s' % (inserted_id))

    return inserted_id


def fetch_data(jodel_id, skip):
    if skip is None:
        return j.get_post_details_v3(jodel_id)
    else:
        return j.get_post_details_v3(jodel_id, skip=skip)

logger.debug('Script invoked')

run_once()

connect_string = "dbname='%s' user='%s' host='%s' password='%s' port='%s'" % (settings['general']['db_name'], settings['general']['db_user'], settings['general']['db_host'], settings['general']['db_pass'], settings['general']['db_port'])
try:
    conn = psycopg2.connect(connect_string)
except:
    logger.error('Database error')
    sys.exit(1)


j = init()
if j is None:
    logger.error('Could not log in, terminating now')

    conn.rollback()
    conn.close()
    sys.exit(1)

cur = conn.cursor(cursor_factory = psycopg2.extras.DictCursor)
cur.execute("""SELECT id, jodel_id, next_post_id FROM jodel WHERE poll = 1 ORDER BY id ASC""")
jodels = cur.fetchall()
cur.close()

failed_jodels = 0
success = True
for jodel in jodels:
    jodel_id = jodel['jodel_id']
    jodel_fk = jodel['id']

    logger.info('Processing jodel %s (database id %s)' % (jodel_id, jodel_fk))

    skip = jodel['next_post_id']
    while True:
        data = fetch_data(jodel_id, skip)

        if data[0] != 200 and failed_jodels < 3:
            failed_jodels += 1
            logger.error('Unable to fetch data, error code %s, trying again' % (data[0], ))
            data = fetch_data(jodel_id, skip)

        if data[0] != 200:
            failed_jodels += 1
            logger.error('Unable to fetch data, error code %s, terminating after commit' % (data[0], ))
            success = False
            break

        process_post(data[1]['details'], jodel_fk)
        for reply in data[1]['replies']:
            process_post(reply, jodel_fk)
       
        skip = data[1]['next']
        if skip is not None:
            cur = conn.cursor()
            cur.execute("""UPDATE jodel SET next_post_id = %s WHERE id = %s""", (skip, jodel_fk))
            cur.close()

        if data[1]['remaining'] == 0:
            break

        conn.commit()

        seconds = randint(3, 8)
        logger.debug('Sleeping %s seconds' % (seconds, ))
        time.sleep(seconds)

    conn.commit()

    seconds = randint(3, 8)
    logger.debug('Sleeping %s seconds' % (seconds, ))
    time.sleep(seconds)

conn.commit()

logger.debug('Checking for images not yet downloaded')

cur = conn.cursor(cursor_factory = psycopg2.extras.DictCursor)
cur.execute("""SELECT id, image_url, thumbnail_url, image_id, thumbnail_id FROM message WHERE (image_url IS NOT NULL AND image_id IS NULL) OR (thumbnail_url IS NOT NULL and thumbnail_id IS NULL)""")
row = cur.fetchone()
while row:
    logger.debug('Processing message %s' % (row['id']))

    if row['image_url'] and not row['image_id']:
        logger.debug('image_id is null')

        image_id = download_image(row['image_url'])
        if image_id:
            cur2 = conn.cursor()
            cur2.execute("""UPDATE message SET image_id = %s WHERE id = %s""", (image_id, row['id']))
            cur2.close()

    if row['thumbnail_url'] and not row['thumbnail_id']:
        logger.debug('thubmnail_id is null')

        thumbnail_id = download_image(row['thumbnail_url'])
        if thumbnail_id:
            cur2 = conn.cursor()
            cur2.execute("""UPDATE message SET thumbnail_id = %s WHERE id = %s""", (thumbnail_id, row['id']))
            cur2.close()


    row = cur.fetchone()

    conn.commit()

    seconds = randint(3, 8)
    logger.debug('Sleeping %s seconds' % (seconds, ))
    time.sleep(seconds)

conn.commit()
conn.close()

if success:
    touch_file = os.path.dirname(os.path.realpath(__file__)) + '/../tmp/last_update'
    with open(touch_file, 'a'):
        os.utime(touch_file)


logger.debug('Script completed')

