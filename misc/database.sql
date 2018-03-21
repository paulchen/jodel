--
-- PostgreSQL database dump
--

-- Dumped from database version 9.6.7
-- Dumped by pg_dump version 9.6.7

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: plpgsql; Type: EXTENSION; Schema: -; Owner: 
--

CREATE EXTENSION IF NOT EXISTS plpgsql WITH SCHEMA pg_catalog;


--
-- Name: EXTENSION plpgsql; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION plpgsql IS 'PL/pgSQL procedural language';


SET search_path = public, pg_catalog;

SET default_tablespace = '';

SET default_with_oids = false;

--
-- Name: config; Type: TABLE; Schema: public; Owner: jodel
--

CREATE TABLE config (
    key character varying(100) NOT NULL,
    value character varying(100) NOT NULL
);


ALTER TABLE config OWNER TO jodel;

--
-- Name: message; Type: TABLE; Schema: public; Owner: jodel
--

CREATE TABLE message (
    id integer NOT NULL,
    message character varying(1000) NOT NULL,
    created_at timestamp without time zone NOT NULL,
    replier integer NOT NULL,
    post_id character varying(30) NOT NULL,
    vote_count integer NOT NULL,
    got_thanks boolean NOT NULL,
    user_handle character varying(30) NOT NULL,
    color character varying(6) NOT NULL,
    post_own character varying(30) NOT NULL,
    distance integer NOT NULL,
    location_name character varying(100) NOT NULL,
    from_home boolean NOT NULL,
    image_url character varying(1000),
    thumbnail_url character varying(1000)
);


ALTER TABLE message OWNER TO jodel;

--
-- Name: message_id_seq; Type: SEQUENCE; Schema: public; Owner: jodel
--

CREATE SEQUENCE message_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE message_id_seq OWNER TO jodel;

--
-- Name: message_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: jodel
--

ALTER SEQUENCE message_id_seq OWNED BY message.id;


--
-- Name: message id; Type: DEFAULT; Schema: public; Owner: jodel
--

ALTER TABLE ONLY message ALTER COLUMN id SET DEFAULT nextval('message_id_seq'::regclass);


--
-- Name: config config_pkey; Type: CONSTRAINT; Schema: public; Owner: jodel
--

ALTER TABLE ONLY config
    ADD CONSTRAINT config_pkey PRIMARY KEY (key);


--
-- Name: message message_pkey; Type: CONSTRAINT; Schema: public; Owner: jodel
--

ALTER TABLE ONLY message
    ADD CONSTRAINT message_pkey PRIMARY KEY (id);


--
-- Name: message message_post_id_key; Type: CONSTRAINT; Schema: public; Owner: jodel
--

ALTER TABLE ONLY message
    ADD CONSTRAINT message_post_id_key UNIQUE (post_id);


--
-- PostgreSQL database dump complete
--

