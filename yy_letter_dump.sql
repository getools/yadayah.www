--
-- PostgreSQL database dump
--

\restrict BghnqHfcTtzD5atDXHu5N2OnoCCKlLckKNCIJJ8vs2Okpon26G0rPpXXs1aqVmI

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
-- Name: rev_yy_letter; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rev_yy_letter (
    _key bigint NOT NULL,
    letter_key integer,
    letter_yt character(1),
    letter_hebrew character(1),
    letter_label character varying(32),
    letter_overview text,
    letter_sort smallint,
    _remove_dtime timestamp without time zone,
    _revision_count integer DEFAULT 0 NOT NULL,
    _revision_user_key integer DEFAULT 0 NOT NULL,
    _revision_dtime timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    letter_numeric_value smallint
);


--
-- Name: rev_yy_letter__key_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rev_yy_letter__key_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rev_yy_letter__key_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rev_yy_letter__key_seq OWNED BY public.rev_yy_letter._key;


--
-- Name: yy_letter; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.yy_letter (
    letter_key integer NOT NULL,
    letter_yt character(1),
    letter_hebrew character(1),
    letter_label character varying(32),
    letter_overview text,
    letter_sort smallint DEFAULT 0 NOT NULL,
    letter_numeric_value smallint
);


--
-- Name: yy_letter_letter_key_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.yy_letter_letter_key_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: yy_letter_letter_key_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.yy_letter_letter_key_seq OWNED BY public.yy_letter.letter_key;


--
-- Name: rev_yy_letter _key; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rev_yy_letter ALTER COLUMN _key SET DEFAULT nextval('public.rev_yy_letter__key_seq'::regclass);


--
-- Name: yy_letter letter_key; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.yy_letter ALTER COLUMN letter_key SET DEFAULT nextval('public.yy_letter_letter_key_seq'::regclass);


--
-- Data for Name: rev_yy_letter; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.rev_yy_letter (_key, letter_key, letter_yt, letter_hebrew, letter_label, letter_overview, letter_sort, _remove_dtime, _revision_count, _revision_user_key, _revision_dtime, letter_numeric_value) FROM stdin;
1	1	\N	\N	\N	<p>"For your edification, the following chart has been designed to present the evolution of each of the twenty-two letters which comprise the Hebrew alphabet. \n  It reveals how they were first drawn in Ancient Hebrew. Their progression is to a script most commonly seen on the Dead Sea Scrolls.</p>\n\n<p>The presentation of Hebrew nomenclature then concludes with the Modern Hebrew form. Each letter’s English equivalent and phonetic, or transliterated, sound \n  is depicted in addition to the character’s current name. The last column describes the image revealed by the original letter.</p>\n\n<p>Five of these twenty-two letters are vowels, representing open-mouth sounds. Their names have been italicized so that they are readily recognizable. \n  The remaining 17 are consonants.</p>\n\n<p>Nine letters lean to the left, which is the direction Hebrew reads. One of them, the Tsade, is directional but could be interpreted leaning either way, making \n  the letter controversial. The Gimal is the bad boy among Hebrew characters. It is the only one moving against the flow and thus is used to write words \n  with less than admirable implications such as gowy.</p>\n\n<p>We have discovered that, when directional letters look toward those in Yahowah’s name, the word’s connotations are positive. \n  If the letters are turned away from His name, these terms are decidedly negative." </p>	0	\N	1	0	2026-02-13 22:28:08.010089	\N
2	2	a	א	aleph	Aleph is one of five vowels in Hebrew. The others are 'ayin, hey, wah and hey.\nInitially drawn in the form of a ram’s head, it conveys strength and power in addition to the ability to lead and protect the flock.\n  As such, the 'Aleph is the first letter in the title God: ‘el – which means“Almighty.”It presents God as someone with the“ability to teach”and“the authority to lead.”\n  The pictograph 'Aleph represents a ram's head as a symbol of authority and power, the leader of a flock; Almighty God. \n  Some Hebrew dictionaries and lexicons represent the vowel 'Aleph with an apostrophe, while others do not.	1	\N	1	0	2026-02-13 22:28:08.010089	1
3	3	b	ב	beyth	  Beyth, means “home and family,”and when used as a preposition means“in”or“with."\n  Beyth in turn is the root of beryth – the Hebrew word translated“Covenant.”thereby explaining the nature of the intended relationship. That is why the character was originally drawn \n  to depict the floor plan of a home – one with a singular entrance or doorway. Brought together, these concepts convey God opening the door and inviting us into His home to be with \n  Him and to be part of His family.	2	\N	1	0	2026-02-13 22:28:08.010089	2
4	4	c	ג	gimal	  Gimal, is the most controversial letter in the Hebrew alphabet. Of the ten directional symbols, only the Gimal points to the right and is oriented in opposition to the Hebrew language which reads right to left.\n  The pictograph gimal represents a foot walking counter to Yahowah's instructions, and can also mean "to gather."	3	\N	1	0	2026-02-13 22:28:08.010089	3
5	5	d	ד	dalet	  Dalet was drawn as an entrance or doorway. Affirming this, even today, dalet means“door" in Hebrew.	4	\N	1	0	2026-02-13 22:28:08.010089	4
6	6	e	ה	hey	Hey is one of five vowels in Hebrew. The others are 'aleph, 'ayin, yowd and wah. \n  The Hey was originally depicted by drawing a person looking up, reaching up, and pointing to the heavens. As such, it means to observe. \n  As a living legacy of this connotation, we find that the Hebrew word hey still means “behold, look and see, take notice, and consider what is revealed.” \n  For those seeking God, for those reaching up to Him for help, all they need do is reach for His Towrah and observe what it reveals.\n  The highly distinctive Hey was drawn in the form of a person standing up, pointing and reaching up to the heavens. It screams, pay attention, be observant, \n  and take notice of what God has done and said. Today, hey still means “Hey, I’m over here! Look at me! Pay attention!”	5	\N	1	0	2026-02-13 22:28:08.010089	5
7	7	f	ו	wah	  The wah was drawn in the form of a tent peg, and is thus symbolic of enlarging and securing a tent home and shelter (Is 54). \n  The Wah speaks of making connections and adding to something, as is characterized by the conjunction “wa – and” in Hebrew today. \n  The Wah therefore addresses the Spirit’s role in enlarging and enriching, even empowering, Yahowah’s Covenant family. 	6	\N	1	0	2026-02-13 22:28:08.010089	6
8	8	z	ז	zayin	  Zayin was drawn in the form of a plow and spoke of cultivating the ground so that it becomes receptive, of sowing seeds for future harvests, and of creating separation and division.\n  Zayin as 7 conveys the seventh month, the time of promise, by depicting a plow removing the weeds while turning over the ground and preparing it to receive nutrients and \n  support new growth when moving in the correct direction.	7	\N	1	0	2026-02-13 22:28:08.010089	7
9	9	h	ח	chet	  Chet is represented by a tent wall and conveys the idea of being protected by being separated from that which is destructive and deadly.” Chet represents a fence, something that protects and separates.\n  In Ancient Hebrew, it spoke of surrounding and enclosing, separating and protecting those one treasures. As the number 8, Chet represents eternity. 	8	\N	1	0	2026-02-13 22:28:08.010089	8
10	10	u	ט	theth	  Theth represents gestation, and therefore is indicative of adding children to a family. Nine adds another individual to eternity, making the experience richer.\n  Theth graphically depicts a basket in which things of value which are harvested and collected are carried and protected.  \n  Theth is akin to the English “t,” but most often is spoken with a “th” pronunciation.\n  The Hebrew Theth is akin to the English “t,” but most often is spoken with a “th” pronunciation.	9	\N	1	0	2026-02-13 22:28:08.010089	9
11	11	i	י	yowd	  Yowd is based upon, and drawn to depict, a yad, the Hebrew word for“hand.” It conveys the idea of reaching out to accomplish something. Especially relevant in this regard, a \n  Yowd reveals that God is reaching down and out to us with an open hand because He wants to lift us up and raise us as His children. In particular, the Yowd was not communicated \n  with a closed fist engendering fear but, instead, as an open hand extended in friendship and support.\n  Some lexicons transliterate the Hebrew Yowd with an “i,” some with a “y,” while others use both to designate the source of the sound.	10	\N	1	0	2026-02-13 22:28:08.010089	10
12	12	k	כ	kaph	Kaph is presented by way of the open palm of an outstretched hand. It conveys all of the things we’d normally associate with an extended and open hand: "a greeting and welcome, an offer of \n  assistance and support, friendship and provision,” even “a vow of inclusion.” With the fingers out and palm up, the Kaph speaks of being open and receptive, even welcoming.	11	\N	1	0	2026-02-13 22:28:08.010089	20
13	13	l	ל	lamed	Lamed was drawn in the shape of a shepherd’s staff. It conveys leadership, direction, guidance, nurturing, and protection. Used commonly as a prefix, a Lamed serves as a preposition in Hebrew, \n  communicating movement toward a goal – in this case toward God, Himself. As a result, Lamed conveys leadership and guidance, providing direction and assistance, nurturing and protection. \n  Of particular interest, the staff is depicted with the crook lowered and, thus, in a position to rescue a fallen lamb.	12	\N	1	0	2026-02-13 22:28:08.010089	30
14	14	m	מ	mem	\N	13	\N	1	0	2026-02-13 22:28:08.010089	40
15	15	n	נ	nun	The ancient Hebrew Nun looks like a sperm but is said to be a seed taking root. It speaks of“children, heirs, inheritance, and the continuance of life.”	14	\N	1	0	2026-02-13 22:28:08.010089	50
16	16	x	ס	samech	The Samech was drawn to depict a sign conveying guidance – which leads to the Covenant and to rational thinking. The graphic symbol was depicted as a sign pointing us in the right direction. \n  Prior to Masorete malfeasance, there was no distinction between a Shin and Sin as there is today. The Shin provided the “sh” sound while the Samech produced the simple “s” sound.	15	\N	1	0	2026-02-13 22:28:08.010089	60
17	17	o	ע	ayin	Ayin is one of five vowels in Hebrew. The others are 'aleph, yowd, hey and wah.\n Some Hebrew dictionaries and lexicons represent the vowel 'Ayin with an apostrophe, while others do not.	16	\N	1	0	2026-02-13 22:28:08.010089	70
18	18	p	פ	peh	 	17	\N	1	0	2026-02-13 22:28:08.010089	80
19	19	y	צ	tsade	\N	18	\N	1	0	2026-02-13 22:28:08.010089	90
20	20	q	ק	qoph	\N	19	\N	1	0	2026-02-13 22:28:08.010089	100
21	21	r	ר	rosh	\N	20	\N	1	0	2026-02-13 22:28:08.010089	200
22	22	s	ש	shin	 The Hebrew letter Shin is most similar to the English “s,” it usually conveys a “sh” sound	21	\N	1	0	2026-02-13 22:28:08.010089	300
23	23	t	ת	taw	\N	22	\N	1	0	2026-02-13 22:28:08.010089	400
\.


--
-- Data for Name: yy_letter; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.yy_letter (letter_key, letter_yt, letter_hebrew, letter_label, letter_overview, letter_sort, letter_numeric_value) FROM stdin;
1	\N	\N	\N	<p>"For your edification, the following chart has been designed to present the evolution of each of the twenty-two letters which comprise the Hebrew alphabet. \n  It reveals how they were first drawn in Ancient Hebrew. Their progression is to a script most commonly seen on the Dead Sea Scrolls.</p>\n\n<p>The presentation of Hebrew nomenclature then concludes with the Modern Hebrew form. Each letter’s English equivalent and phonetic, or transliterated, sound \n  is depicted in addition to the character’s current name. The last column describes the image revealed by the original letter.</p>\n\n<p>Five of these twenty-two letters are vowels, representing open-mouth sounds. Their names have been italicized so that they are readily recognizable. \n  The remaining 17 are consonants.</p>\n\n<p>Nine letters lean to the left, which is the direction Hebrew reads. One of them, the Tsade, is directional but could be interpreted leaning either way, making \n  the letter controversial. The Gimal is the bad boy among Hebrew characters. It is the only one moving against the flow and thus is used to write words \n  with less than admirable implications such as gowy.</p>\n\n<p>We have discovered that, when directional letters look toward those in Yahowah’s name, the word’s connotations are positive. \n  If the letters are turned away from His name, these terms are decidedly negative." </p>	0	\N
2	a	א	aleph	Aleph is one of five vowels in Hebrew. The others are 'ayin, hey, wah and hey.\nInitially drawn in the form of a ram’s head, it conveys strength and power in addition to the ability to lead and protect the flock.\n  As such, the 'Aleph is the first letter in the title God: ‘el – which means“Almighty.”It presents God as someone with the“ability to teach”and“the authority to lead.”\n  The pictograph 'Aleph represents a ram's head as a symbol of authority and power, the leader of a flock; Almighty God. \n  Some Hebrew dictionaries and lexicons represent the vowel 'Aleph with an apostrophe, while others do not.	1	1
3	b	ב	beyth	  Beyth, means “home and family,”and when used as a preposition means“in”or“with."\n  Beyth in turn is the root of beryth – the Hebrew word translated“Covenant.”thereby explaining the nature of the intended relationship. That is why the character was originally drawn \n  to depict the floor plan of a home – one with a singular entrance or doorway. Brought together, these concepts convey God opening the door and inviting us into His home to be with \n  Him and to be part of His family.	2	2
4	c	ג	gimal	  Gimal, is the most controversial letter in the Hebrew alphabet. Of the ten directional symbols, only the Gimal points to the right and is oriented in opposition to the Hebrew language which reads right to left.\n  The pictograph gimal represents a foot walking counter to Yahowah's instructions, and can also mean "to gather."	3	3
5	d	ד	dalet	  Dalet was drawn as an entrance or doorway. Affirming this, even today, dalet means“door" in Hebrew.	4	4
6	e	ה	hey	Hey is one of five vowels in Hebrew. The others are 'aleph, 'ayin, yowd and wah. \n  The Hey was originally depicted by drawing a person looking up, reaching up, and pointing to the heavens. As such, it means to observe. \n  As a living legacy of this connotation, we find that the Hebrew word hey still means “behold, look and see, take notice, and consider what is revealed.” \n  For those seeking God, for those reaching up to Him for help, all they need do is reach for His Towrah and observe what it reveals.\n  The highly distinctive Hey was drawn in the form of a person standing up, pointing and reaching up to the heavens. It screams, pay attention, be observant, \n  and take notice of what God has done and said. Today, hey still means “Hey, I’m over here! Look at me! Pay attention!”	5	5
7	f	ו	wah	  The wah was drawn in the form of a tent peg, and is thus symbolic of enlarging and securing a tent home and shelter (Is 54). \n  The Wah speaks of making connections and adding to something, as is characterized by the conjunction “wa – and” in Hebrew today. \n  The Wah therefore addresses the Spirit’s role in enlarging and enriching, even empowering, Yahowah’s Covenant family. 	6	6
8	z	ז	zayin	  Zayin was drawn in the form of a plow and spoke of cultivating the ground so that it becomes receptive, of sowing seeds for future harvests, and of creating separation and division.\n  Zayin as 7 conveys the seventh month, the time of promise, by depicting a plow removing the weeds while turning over the ground and preparing it to receive nutrients and \n  support new growth when moving in the correct direction.	7	7
9	h	ח	chet	  Chet is represented by a tent wall and conveys the idea of being protected by being separated from that which is destructive and deadly.” Chet represents a fence, something that protects and separates.\n  In Ancient Hebrew, it spoke of surrounding and enclosing, separating and protecting those one treasures. As the number 8, Chet represents eternity. 	8	8
10	u	ט	theth	  Theth represents gestation, and therefore is indicative of adding children to a family. Nine adds another individual to eternity, making the experience richer.\n  Theth graphically depicts a basket in which things of value which are harvested and collected are carried and protected.  \n  Theth is akin to the English “t,” but most often is spoken with a “th” pronunciation.\n  The Hebrew Theth is akin to the English “t,” but most often is spoken with a “th” pronunciation.	9	9
11	i	י	yowd	  Yowd is based upon, and drawn to depict, a yad, the Hebrew word for“hand.” It conveys the idea of reaching out to accomplish something. Especially relevant in this regard, a \n  Yowd reveals that God is reaching down and out to us with an open hand because He wants to lift us up and raise us as His children. In particular, the Yowd was not communicated \n  with a closed fist engendering fear but, instead, as an open hand extended in friendship and support.\n  Some lexicons transliterate the Hebrew Yowd with an “i,” some with a “y,” while others use both to designate the source of the sound.	10	10
12	k	כ	kaph	Kaph is presented by way of the open palm of an outstretched hand. It conveys all of the things we’d normally associate with an extended and open hand: "a greeting and welcome, an offer of \n  assistance and support, friendship and provision,” even “a vow of inclusion.” With the fingers out and palm up, the Kaph speaks of being open and receptive, even welcoming.	11	20
13	l	ל	lamed	Lamed was drawn in the shape of a shepherd’s staff. It conveys leadership, direction, guidance, nurturing, and protection. Used commonly as a prefix, a Lamed serves as a preposition in Hebrew, \n  communicating movement toward a goal – in this case toward God, Himself. As a result, Lamed conveys leadership and guidance, providing direction and assistance, nurturing and protection. \n  Of particular interest, the staff is depicted with the crook lowered and, thus, in a position to rescue a fallen lamb.	12	30
14	m	מ	mem	\N	13	40
15	n	נ	nun	The ancient Hebrew Nun looks like a sperm but is said to be a seed taking root. It speaks of“children, heirs, inheritance, and the continuance of life.”	14	50
16	x	ס	samech	The Samech was drawn to depict a sign conveying guidance – which leads to the Covenant and to rational thinking. The graphic symbol was depicted as a sign pointing us in the right direction. \n  Prior to Masorete malfeasance, there was no distinction between a Shin and Sin as there is today. The Shin provided the “sh” sound while the Samech produced the simple “s” sound.	15	60
17	o	ע	ayin	Ayin is one of five vowels in Hebrew. The others are 'aleph, yowd, hey and wah.\n Some Hebrew dictionaries and lexicons represent the vowel 'Ayin with an apostrophe, while others do not.	16	70
18	p	פ	peh	 	17	80
19	y	צ	tsade	\N	18	90
20	q	ק	qoph	\N	19	100
21	r	ר	rosh	\N	20	200
22	s	ש	shin	 The Hebrew letter Shin is most similar to the English “s,” it usually conveys a “sh” sound	21	300
23	t	ת	taw	\N	22	400
\.


--
-- Name: rev_yy_letter__key_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.rev_yy_letter__key_seq', 23, true);


--
-- Name: yy_letter_letter_key_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.yy_letter_letter_key_seq', 23, true);


--
-- Name: rev_yy_letter rev_yy_letter_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rev_yy_letter
    ADD CONSTRAINT rev_yy_letter_pkey PRIMARY KEY (_key);


--
-- Name: yy_letter yy_letter_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.yy_letter
    ADD CONSTRAINT yy_letter_pkey PRIMARY KEY (letter_key);


--
-- Name: idx_rev_yy_letter_letter_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rev_yy_letter_letter_key ON public.rev_yy_letter USING btree (letter_key);


--
-- Name: yy_letter trg_yy_letter_ai; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_yy_letter_ai AFTER INSERT ON public.yy_letter FOR EACH ROW EXECUTE FUNCTION public.trg_yy_letter_revision();


--
-- Name: yy_letter trg_yy_letter_au; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_yy_letter_au AFTER UPDATE ON public.yy_letter FOR EACH ROW EXECUTE FUNCTION public.trg_yy_letter_revision();


--
-- Name: yy_letter trg_yy_letter_bd; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_yy_letter_bd BEFORE DELETE ON public.yy_letter FOR EACH ROW EXECUTE FUNCTION public.trg_yy_letter_revision();


--
-- PostgreSQL database dump complete
--

\unrestrict BghnqHfcTtzD5atDXHu5N2OnoCCKlLckKNCIJJ8vs2Okpon26G0rPpXXs1aqVmI

