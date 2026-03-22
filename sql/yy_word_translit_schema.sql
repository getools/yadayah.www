--
-- PostgreSQL database dump
--

\restrict TDgdnu1S7vacvgNsanpVaRQze2wdwg8xGaM2MI7Yu1ypFHfxiQkfU9vBFg6PxnE

-- Dumped from database version 15.15
-- Dumped by pg_dump version 15.15

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: yy_word_translit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.yy_word_translit (
    word_translit_key integer NOT NULL,
    word_key integer,
    word_translit_text character varying(250) NOT NULL,
    word_translit_sort smallint DEFAULT 0,
    word_translit_count_yy smallint,
    user_key integer,
    word_translit_dtime timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    word_translit_fix character varying(250)
);


--
-- Name: yy_word_translit_word_translit_key_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.yy_word_translit_word_translit_key_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: yy_word_translit_word_translit_key_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.yy_word_translit_word_translit_key_seq OWNED BY public.yy_word_translit.word_translit_key;


--
-- Name: yy_word_translit word_translit_key; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.yy_word_translit ALTER COLUMN word_translit_key SET DEFAULT nextval('public.yy_word_translit_word_translit_key_seq'::regclass);


--
-- Name: yy_word_translit yy_word_translit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.yy_word_translit
    ADD CONSTRAINT yy_word_translit_pkey PRIMARY KEY (word_translit_key);


--
-- Name: yy_word_translit set_word_translit_user_key; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER set_word_translit_user_key BEFORE INSERT ON public.yy_word_translit FOR EACH ROW EXECUTE FUNCTION public.trg_set_word_translit_user_key();


--
-- Name: yy_word_translit trg_translit_count_recalc; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_translit_count_recalc AFTER INSERT OR DELETE OR UPDATE OF word_translit_count_yy, word_key ON public.yy_word_translit FOR EACH ROW EXECUTE FUNCTION public.trg_recalc_word_count_yy();


--
-- Name: yy_word_translit yy_word_translit_revision_ai; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER yy_word_translit_revision_ai AFTER INSERT ON public.yy_word_translit FOR EACH ROW EXECUTE FUNCTION public.trg_yy_word_translit_revision();


--
-- Name: yy_word_translit yy_word_translit_revision_au; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER yy_word_translit_revision_au AFTER UPDATE ON public.yy_word_translit FOR EACH ROW EXECUTE FUNCTION public.trg_yy_word_translit_revision();


--
-- Name: yy_word_translit yy_word_translit_revision_bd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER yy_word_translit_revision_bd BEFORE DELETE ON public.yy_word_translit FOR EACH ROW EXECUTE FUNCTION public.trg_yy_word_translit_revision();


--
-- Name: yy_word_translit yy_word_translit_user_key_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.yy_word_translit
    ADD CONSTRAINT yy_word_translit_user_key_fkey FOREIGN KEY (user_key) REFERENCES public.yy_user(yy_user_key);


--
-- Name: yy_word_translit yy_word_translit_word_key_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.yy_word_translit
    ADD CONSTRAINT yy_word_translit_word_key_fkey FOREIGN KEY (word_key) REFERENCES public.yy_word(word_key);


--
-- PostgreSQL database dump complete
--

\unrestrict TDgdnu1S7vacvgNsanpVaRQze2wdwg8xGaM2MI7Yu1ypFHfxiQkfU9vBFg6PxnE

