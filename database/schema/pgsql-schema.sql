--
-- PostgreSQL database dump
--

\restrict FpnGYWeaeZVEyIqJnPxEmI49rJbXeU2haWcGfJ0vtgxTAiE9PcaYUiJfEaGV1wX

-- Dumped from database version 16.14
-- Dumped by pg_dump version 18.4

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
-- Name: activity_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.activity_logs (
    id bigint NOT NULL,
    user_id bigint,
    action character varying(255) NOT NULL,
    loggable_type character varying(255) NOT NULL,
    loggable_id character varying(255),
    old_values json,
    new_values json,
    ip_address character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_agent text,
    description character varying(255),
    metadata json,
    target_user_id bigint,
    scope_type character varying(255),
    scope_id bigint,
    role character varying(255),
    reason text,
    organization_id bigint
);


--
-- Name: activity_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.activity_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: activity_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.activity_logs_id_seq OWNED BY public.activity_logs.id;


--
-- Name: archived_strategic_objectives; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.archived_strategic_objectives (
    id bigint NOT NULL,
    original_id bigint NOT NULL,
    code character varying(20) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    portfolio_id bigint,
    bsc_perspective character varying(50),
    target_value numeric(15,2),
    measurement_unit character varying(50),
    current_value numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    baseline_value numeric(15,2),
    start_date date,
    end_date date,
    weight numeric(5,2) DEFAULT '1'::numeric NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    "order" smallint DEFAULT '0'::smallint NOT NULL,
    owner_id bigint,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    archived_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    archive_reason character varying(500) DEFAULT 'PMI restructuring: objectives layer removed'::character varying NOT NULL
);


--
-- Name: archived_strategic_objectives_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.archived_strategic_objectives_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: archived_strategic_objectives_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.archived_strategic_objectives_id_seq OWNED BY public.archived_strategic_objectives.id;


--
-- Name: attachments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.attachments (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    attachable_type character varying(255) NOT NULL,
    attachable_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    file_path character varying(255) NOT NULL,
    file_type character varying(255),
    file_size bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: attachments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.attachments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: attachments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.attachments_id_seq OWNED BY public.attachments.id;


--
-- Name: authorization_assignment_audits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.authorization_assignment_audits (
    id bigint NOT NULL,
    event character varying(50) NOT NULL,
    actor_id bigint,
    target_user_id bigint,
    scope_type character varying(50),
    scope_id bigint,
    role character varying(255),
    old_value json,
    new_value json,
    reason text,
    ip_address character varying(45),
    user_agent character varying(255),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: COLUMN authorization_assignment_audits.event; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.authorization_assignment_audits.event IS 'نوع الحدث: role_assigned, role_revoked, permission_changed, etc.';


--
-- Name: COLUMN authorization_assignment_audits.role; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.authorization_assignment_audits.role IS 'الدور المتأثر';


--
-- Name: COLUMN authorization_assignment_audits.old_value; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.authorization_assignment_audits.old_value IS 'القيمة السابقة';


--
-- Name: COLUMN authorization_assignment_audits.new_value; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.authorization_assignment_audits.new_value IS 'القيمة الجديدة';


--
-- Name: COLUMN authorization_assignment_audits.reason; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.authorization_assignment_audits.reason IS 'سبب التغيير';


--
-- Name: authorization_decision_audits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.authorization_decision_audits (
    id bigint NOT NULL,
    user_id bigint,
    authorization_resource_id bigint NOT NULL,
    action character varying(255) NOT NULL,
    decision character varying(16) NOT NULL,
    matched_authorization_role_id bigint,
    matched_authorization_role_assignment_id bigint,
    matched_authorization_record_rule_id bigint,
    source character varying(16) DEFAULT 'engine'::character varying NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT authorization_decision_audits_decision_check CHECK (((decision)::text = ANY (ARRAY[('allow'::character varying)::text, ('deny'::character varying)::text]))),
    CONSTRAINT authorization_decision_audits_source_check CHECK (((source)::text = ANY (ARRAY[('engine'::character varying)::text, ('shadow'::character varying)::text, ('legacy'::character varying)::text])))
);


--
-- Name: authorization_decision_audits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.authorization_decision_audits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: authorization_decision_audits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.authorization_decision_audits_id_seq OWNED BY public.authorization_decision_audits.id;


--
-- Name: authorization_record_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.authorization_record_rules (
    id bigint NOT NULL,
    authorization_role_id bigint,
    user_id bigint,
    authorization_resource_id bigint NOT NULL,
    action character varying(255),
    domain_json jsonb NOT NULL,
    priority integer DEFAULT 0 NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: authorization_record_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.authorization_record_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: authorization_record_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.authorization_record_rules_id_seq OWNED BY public.authorization_record_rules.id;


--
-- Name: authorization_resources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.authorization_resources (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    label character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: authorization_resources_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.authorization_resources_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: authorization_resources_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.authorization_resources_id_seq OWNED BY public.authorization_resources.id;


--
-- Name: authorization_role_assignments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.authorization_role_assignments (
    id bigint NOT NULL,
    authorization_role_id bigint NOT NULL,
    user_id bigint NOT NULL,
    scope_type character varying(32) NOT NULL,
    scope_id bigint,
    organization_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    inherit_to_children boolean DEFAULT true NOT NULL,
    expires_at timestamp(0) with time zone,
    source character varying(16) DEFAULT 'manual'::character varying NOT NULL,
    granted_by bigint,
    CONSTRAINT authorization_role_assignments_scope_id_allows_null_check CHECK (((((scope_type)::text = ANY (ARRAY[('all'::character varying)::text, ('own'::character varying)::text])) AND (scope_id IS NULL)) OR (((scope_type)::text <> ALL (ARRAY[('all'::character varying)::text, ('own'::character varying)::text])) AND (scope_id IS NOT NULL)))),
    CONSTRAINT authorization_role_assignments_scope_type_check CHECK (((scope_type)::text = ANY ((ARRAY['all'::character varying, 'organization'::character varying, 'department'::character varying, 'own'::character varying, 'project'::character varying, 'program'::character varying, 'portfolio'::character varying, 'kpi'::character varying, 'meeting'::character varying, 'survey'::character varying])::text[]))),
    CONSTRAINT authorization_role_assignments_source_check CHECK (((source)::text = ANY (ARRAY[('manual'::character varying)::text, ('auto'::character varying)::text, ('migration'::character varying)::text])))
);


--
-- Name: authorization_role_assignments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.authorization_role_assignments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: authorization_role_assignments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.authorization_role_assignments_id_seq OWNED BY public.authorization_role_assignments.id;


--
-- Name: authorization_role_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.authorization_role_permissions (
    authorization_role_id bigint NOT NULL,
    authorization_resource_id bigint NOT NULL,
    action character varying(255) NOT NULL,
    reach jsonb
);


--
-- Name: authorization_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.authorization_roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    label character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_admin_role boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    label_ar character varying(255),
    label_en character varying(255),
    scope_type character varying(255) DEFAULT 'organization'::character varying NOT NULL,
    is_system boolean DEFAULT false NOT NULL
);


--
-- Name: authorization_roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.authorization_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: authorization_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.authorization_roles_id_seq OWNED BY public.authorization_roles.id;


--
-- Name: blockers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.blockers (
    id bigint NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    blockable_type character varying(255) NOT NULL,
    blockable_id bigint NOT NULL,
    severity character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    status character varying(255) DEFAULT 'open'::character varying NOT NULL,
    identified_date date NOT NULL,
    expected_resolution_date date,
    resolved_date date,
    resolution text,
    reported_by bigint,
    assigned_to bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    organization_id bigint,
    CONSTRAINT blockers_severity_check CHECK (((severity)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT blockers_status_check CHECK (((status)::text = ANY (ARRAY[('open'::character varying)::text, ('in_progress'::character varying)::text, ('escalated'::character varying)::text, ('resolved'::character varying)::text])))
);


--
-- Name: blockers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.blockers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: blockers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.blockers_id_seq OWNED BY public.blockers.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: comments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comments (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    commentable_type character varying(255) NOT NULL,
    commentable_id bigint NOT NULL,
    content text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: comments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.comments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: comments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.comments_id_seq OWNED BY public.comments.id;


--
-- Name: data_import_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.data_import_requests (
    id bigint NOT NULL,
    response_id bigint NOT NULL,
    template_id bigint,
    target_table character varying(100) NOT NULL,
    target_id bigint,
    operation character varying(20) DEFAULT 'create'::character varying NOT NULL,
    payload json NOT NULL,
    diff json,
    upsert_key_field character varying(100),
    upsert_key_value character varying(255),
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    priority integer DEFAULT 0 NOT NULL,
    requested_at timestamp(0) without time zone NOT NULL,
    reviewed_at timestamp(0) without time zone,
    reviewed_by bigint,
    rejection_reason text,
    applied_at timestamp(0) without time zone,
    applied_id bigint,
    error_message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: data_import_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.data_import_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_import_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.data_import_requests_id_seq OWNED BY public.data_import_requests.id;


--
-- Name: data_mapping_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.data_mapping_templates (
    id bigint NOT NULL,
    survey_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    target_model character varying(100) NOT NULL,
    mappings json NOT NULL,
    insert_policy character varying(20) DEFAULT 'create_only'::character varying NOT NULL,
    conflict_policy character varying(20) DEFAULT 'require_review'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: data_mapping_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.data_mapping_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_mapping_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.data_mapping_templates_id_seq OWNED BY public.data_mapping_templates.id;


--
-- Name: department_capacity_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.department_capacity_roles (
    id bigint NOT NULL,
    department_id bigint NOT NULL,
    capacity character varying(10) NOT NULL,
    role_key character varying(50) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: department_capacity_roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.department_capacity_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: department_capacity_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.department_capacity_roles_id_seq OWNED BY public.department_capacity_roles.id;


--
-- Name: departments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.departments (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    name_en character varying(255),
    code character varying(255),
    description text,
    email character varying(255),
    parent_id bigint,
    manager_id bigint,
    level integer DEFAULT 1 NOT NULL,
    path character varying(255),
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    organization_id bigint,
    deleted_at timestamp(0) without time zone
);


--
-- Name: departments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.departments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: departments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.departments_id_seq OWNED BY public.departments.id;


--
-- Name: email_otps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.email_otps (
    id bigint NOT NULL,
    email character varying(255) NOT NULL,
    purpose character varying(32) NOT NULL,
    code_hash character varying(255) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    consumed_at timestamp(0) without time zone,
    attempts smallint DEFAULT '0'::smallint NOT NULL,
    ip character varying(45),
    user_agent character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: email_otps_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.email_otps_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: email_otps_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.email_otps_id_seq OWNED BY public.email_otps.id;


--
-- Name: employee_certificates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.employee_certificates (
    id bigint NOT NULL,
    employee_profile_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    title character varying(255),
    file_path character varying(255),
    file_name character varying(255),
    mime_type character varying(255),
    file_size integer,
    issued_at date,
    expires_at date,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: employee_certificates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.employee_certificates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: employee_certificates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.employee_certificates_id_seq OWNED BY public.employee_certificates.id;


--
-- Name: employee_personal_info; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.employee_personal_info (
    id bigint NOT NULL,
    employee_profile_id bigint NOT NULL,
    full_name_arabic character varying(255),
    nationality character varying(2),
    gender character varying(10),
    birth_date date,
    address text,
    emergency_contact character varying(255),
    emergency_phone character varying(20),
    emergency_contact_relation character varying(255),
    national_id character varying(10),
    national_id_issue_date date,
    national_id_issue_place character varying(255),
    national_id_expiry_date date,
    national_id_document_path character varying(255),
    iqama_number character varying(10),
    iqama_issue_date date,
    iqama_issue_place character varying(255),
    iqama_expiry_date date,
    iqama_document_path character varying(255),
    profession character varying(255),
    religion character varying(255),
    sponsor character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    full_name_english character varying(255)
);


--
-- Name: employee_personal_info_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.employee_personal_info_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: employee_personal_info_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.employee_personal_info_id_seq OWNED BY public.employee_personal_info.id;


--
-- Name: employee_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.employee_profiles (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    employee_no character varying(255),
    hire_date date,
    employment_type character varying(255) DEFAULT 'full_time'::character varying NOT NULL,
    employment_status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    ministry_hire_date date,
    contract_type character varying(30),
    social_insurance_number character varying(50),
    specialization character varying(255),
    current_work_field character varying(255),
    fingerprint_number character varying(50),
    staff_category character varying(20),
    CONSTRAINT employee_profiles_employment_status_check CHECK (((employment_status)::text = ANY (ARRAY[('active'::character varying)::text, ('suspended'::character varying)::text, ('terminated'::character varying)::text, ('on_leave'::character varying)::text])))
);


--
-- Name: employee_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.employee_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: employee_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.employee_profiles_id_seq OWNED BY public.employee_profiles.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: governance_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.governance_rules (
    id bigint NOT NULL,
    organization_id bigint,
    resource_type character varying(255) NOT NULL,
    resource_subtype character varying(255),
    governing_unit_id bigint,
    capabilities json NOT NULL,
    applies_to_children boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: governance_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.governance_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: governance_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.governance_rules_id_seq OWNED BY public.governance_rules.id;


--
-- Name: programs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.programs (
    id bigint NOT NULL,
    code character varying(20) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    department_id bigint,
    budget numeric(15,2),
    spent_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    start_date date,
    end_date date,
    progress numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    weight numeric(5,2) DEFAULT '1'::numeric NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    created_by bigint,
    "order" smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    portfolio_id bigint,
    total_program_budget numeric(15,2),
    progress_calculation_method character varying(255) DEFAULT 'average'::character varying NOT NULL,
    organization_id bigint,
    CONSTRAINT initiatives_priority_check CHECK (((priority)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT initiatives_status_check CHECK (((status)::text = ANY (ARRAY[('draft'::character varying)::text, ('planning'::character varying)::text, ('in_progress'::character varying)::text, ('on_hold'::character varying)::text, ('completed'::character varying)::text, ('cancelled'::character varying)::text]))),
    CONSTRAINT programs_priority_check CHECK (((priority)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('urgent'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT programs_status_check CHECK (((status)::text = ANY (ARRAY[('draft'::character varying)::text, ('planning'::character varying)::text, ('in_progress'::character varying)::text, ('on_hold'::character varying)::text, ('completed'::character varying)::text, ('cancelled'::character varying)::text])))
);


--
-- Name: initiatives_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.initiatives_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: initiatives_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.initiatives_id_seq OWNED BY public.programs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: kpi_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kpi_links (
    id bigint NOT NULL,
    organization_id bigint NOT NULL,
    kpi_id bigint NOT NULL,
    linkable_type character varying(255) NOT NULL,
    linkable_id bigint NOT NULL,
    relationship_type character varying(50) DEFAULT 'related'::character varying NOT NULL,
    weight numeric(5,2),
    notes text,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: kpi_links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.kpi_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: kpi_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.kpi_links_id_seq OWNED BY public.kpi_links.id;


--
-- Name: kpi_measurements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kpi_measurements (
    id bigint NOT NULL,
    organization_id bigint NOT NULL,
    kpi_id bigint NOT NULL,
    value numeric(15,2) NOT NULL,
    measurement_date date NOT NULL,
    notes text,
    evidence_url character varying(2048),
    source_type character varying(255),
    source_id bigint,
    recorded_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: kpi_measurements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.kpi_measurements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: kpi_measurements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.kpi_measurements_id_seq OWNED BY public.kpi_measurements.id;


--
-- Name: kpis; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kpis (
    id bigint NOT NULL,
    organization_id bigint NOT NULL,
    code character varying(40),
    name character varying(255) NOT NULL,
    description text,
    measurement_method character varying(255),
    category character varying(100),
    baseline numeric(15,2),
    target numeric(15,2),
    current_value numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    unit character varying(50),
    frequency character varying(255) DEFAULT 'monthly'::character varying NOT NULL,
    direction character varying(255) DEFAULT 'increase'::character varying NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    owner_id bigint,
    created_by bigint,
    "order" smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    department_id bigint,
    CONSTRAINT kpis_direction_check CHECK (((direction)::text = ANY (ARRAY[('increase'::character varying)::text, ('decrease'::character varying)::text, ('maintain'::character varying)::text]))),
    CONSTRAINT kpis_frequency_check CHECK (((frequency)::text = ANY (ARRAY[('daily'::character varying)::text, ('weekly'::character varying)::text, ('monthly'::character varying)::text, ('quarterly'::character varying)::text, ('yearly'::character varying)::text]))),
    CONSTRAINT kpis_status_check CHECK (((status)::text = ANY (ARRAY[('active'::character varying)::text, ('inactive'::character varying)::text, ('archived'::character varying)::text])))
);


--
-- Name: kpis_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.kpis_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: kpis_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.kpis_id_seq OWNED BY public.kpis.id;


--
-- Name: login_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.login_attempts (
    id bigint NOT NULL,
    email character varying(255) NOT NULL,
    ip_address character varying(45) NOT NULL,
    user_agent character varying(255),
    successful boolean DEFAULT false NOT NULL,
    attempted_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: login_attempts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.login_attempts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: login_attempts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.login_attempts_id_seq OWNED BY public.login_attempts.id;


--
-- Name: meeting_agenda_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.meeting_agenda_items (
    id bigint NOT NULL,
    meeting_id bigint NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    proposed_by_id bigint,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    review_note text,
    organization_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT agenda_items_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('approved'::character varying)::text, ('rejected'::character varying)::text])))
);


--
-- Name: meeting_agenda_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.meeting_agenda_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: meeting_agenda_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.meeting_agenda_items_id_seq OWNED BY public.meeting_agenda_items.id;


--
-- Name: meeting_attendees; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.meeting_attendees (
    meeting_id bigint NOT NULL,
    user_id bigint NOT NULL,
    role character varying(50) DEFAULT 'attendee'::character varying NOT NULL,
    attended boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: meeting_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.meeting_categories (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    organization_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


--
-- Name: meeting_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.meeting_categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: meeting_categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.meeting_categories_id_seq OWNED BY public.meeting_categories.id;


--
-- Name: meeting_resolutions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.meeting_resolutions (
    id bigint NOT NULL,
    reference_number character varying(20),
    organization_id bigint,
    meeting_id bigint NOT NULL,
    kind character varying(20) DEFAULT 'recommendation'::character varying NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    owner_id bigint NOT NULL,
    status character varying(30) DEFAULT 'open'::character varying NOT NULL,
    priority character varying(20) DEFAULT 'medium'::character varying NOT NULL,
    due_date date,
    hold_reason text,
    hold_until timestamp(0) without time zone,
    hold_by bigint,
    hold_at timestamp(0) without time zone,
    created_by bigint NOT NULL,
    completed_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT meeting_resolutions_kind_check CHECK (((kind)::text = ANY (ARRAY[('recommendation'::character varying)::text, ('decision'::character varying)::text]))),
    CONSTRAINT meeting_resolutions_priority_check CHECK (((priority)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT meeting_resolutions_status_check CHECK (((status)::text = ANY (ARRAY[('open'::character varying)::text, ('in_progress'::character varying)::text, ('converted_to_tasks'::character varying)::text, ('completed'::character varying)::text, ('cancelled'::character varying)::text])))
);


--
-- Name: meeting_resolutions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.meeting_resolutions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: meeting_resolutions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.meeting_resolutions_id_seq OWNED BY public.meeting_resolutions.id;


--
-- Name: meeting_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.meeting_settings (
    id bigint NOT NULL,
    organization_id bigint,
    default_duration_minutes smallint DEFAULT '60'::smallint NOT NULL,
    reminder_window_hours smallint DEFAULT '24'::smallint NOT NULL,
    attendee_roles json,
    default_category_id bigint,
    agenda_request_enabled boolean DEFAULT true NOT NULL,
    agenda_request_lead_hours smallint DEFAULT '48'::smallint NOT NULL,
    decision_pending_expiry_days smallint DEFAULT '30'::smallint NOT NULL,
    recommendation_overdue_grace_days smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: meeting_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.meeting_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: meeting_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.meeting_settings_id_seq OWNED BY public.meeting_settings.id;


--
-- Name: meetings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.meetings (
    id bigint NOT NULL,
    reference_number character varying(20),
    title character varying(255) NOT NULL,
    description text,
    scheduled_at timestamp(0) without time zone NOT NULL,
    duration_minutes smallint DEFAULT '60'::smallint NOT NULL,
    location character varying(255),
    virtual_link character varying(2048),
    agenda text,
    minutes text,
    status character varying(20) DEFAULT 'scheduled'::character varying NOT NULL,
    organizer_id bigint,
    subject_type character varying(255),
    subject_id bigint,
    organization_id bigint,
    reminder_sent_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    agenda_requested_at timestamp(0) without time zone,
    category_id bigint,
    department_id bigint,
    CONSTRAINT meetings_status_check CHECK (((status)::text = ANY (ARRAY[('scheduled'::character varying)::text, ('in_progress'::character varying)::text, ('completed'::character varying)::text, ('cancelled'::character varying)::text])))
);


--
-- Name: meetings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.meetings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: meetings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.meetings_id_seq OWNED BY public.meetings.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: milestone_deliverables; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.milestone_deliverables (
    id bigint NOT NULL,
    milestone_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    progress numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    "order" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT milestone_deliverables_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('in_progress'::character varying)::text, ('completed'::character varying)::text, ('cancelled'::character varying)::text])))
);


--
-- Name: milestone_deliverables_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.milestone_deliverables_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: milestone_deliverables_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.milestone_deliverables_id_seq OWNED BY public.milestone_deliverables.id;


--
-- Name: milestones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.milestones (
    id bigint NOT NULL,
    project_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    due_date date,
    completed_date date,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    "order" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    start_date date,
    progress numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT milestones_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('in_progress'::character varying)::text, ('completed'::character varying)::text, ('overdue'::character varying)::text])))
);


--
-- Name: milestones_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.milestones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: milestones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.milestones_id_seq OWNED BY public.milestones.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id uuid NOT NULL,
    type character varying(255) NOT NULL,
    notifiable_type character varying(255) NOT NULL,
    notifiable_id bigint NOT NULL,
    data text NOT NULL,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: organizations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.organizations (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(50) NOT NULL,
    logo character varying(255),
    description text,
    email character varying(255),
    phone character varying(255),
    address character varying(255),
    website character varying(255),
    settings json,
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    parent_id bigint,
    type character varying(32) DEFAULT 'organization'::character varying NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    CONSTRAINT organizations_parent_id_not_self_check CHECK (((parent_id IS NULL) OR (parent_id <> id))),
    CONSTRAINT organizations_type_check CHECK (((type)::text = ANY (ARRAY[('cluster'::character varying)::text, ('hospital'::character varying)::text, ('center'::character varying)::text, ('organization'::character varying)::text, ('other'::character varying)::text])))
);


--
-- Name: COLUMN organizations.code; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.organizations.code IS 'رمز المؤسسة الفريد';


--
-- Name: COLUMN organizations.settings; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.organizations.settings IS 'إعدادات خاصة بالمؤسسة';


--
-- Name: organizations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.organizations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: organizations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.organizations_id_seq OWNED BY public.organizations.id;


--
-- Name: ovr_incident_participants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ovr_incident_participants (
    id bigint NOT NULL,
    incident_report_id uuid NOT NULL,
    user_id bigint NOT NULL,
    invited_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ovr_incident_participants_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ovr_incident_participants_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ovr_incident_participants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ovr_incident_participants_id_seq OWNED BY public.ovr_incident_participants.id;


--
-- Name: ovr_incident_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ovr_incident_reports (
    id uuid NOT NULL,
    report_number character varying(50) NOT NULL,
    organization_id bigint NOT NULL,
    reporter_id bigint NOT NULL,
    reporter_name character varying(255) NOT NULL,
    reporter_email character varying(255),
    reporter_extension character varying(20),
    reporter_job_title character varying(100),
    reporter_department_id bigint,
    reporter_section_id bigint,
    incident_datetime timestamp(0) without time zone NOT NULL,
    is_patient_related boolean DEFAULT false NOT NULL,
    patient_name character varying(255),
    patient_gender character varying(20),
    patient_dob date,
    informed_authority boolean DEFAULT false NOT NULL,
    incident_type_id uuid NOT NULL,
    reportable_incident_type_id uuid,
    incident_description text NOT NULL,
    actions_taken text,
    contributing_factors json,
    immediate_action_required boolean DEFAULT false NOT NULL,
    severity_level character varying(20) NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    assigned_to bigint,
    assigned_at timestamp(0) without time zone,
    due_date timestamp(0) without time zone,
    resolved_at timestamp(0) without time zone,
    closed_at timestamp(0) without time zone,
    closed_by bigint,
    closure_reason text,
    reopened_at timestamp(0) without time zone,
    reopened_by bigint,
    reopen_reason text,
    is_confidential boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    sla_notified_at timestamp(0) without time zone,
    patient_file_number text,
    tracking_token character varying(64)
);


--
-- Name: ovr_incident_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ovr_incident_types (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    name_ar character varying(255) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    requires_reportable_type boolean DEFAULT false NOT NULL,
    organization_id bigint
);


--
-- Name: ovr_report_comments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ovr_report_comments (
    id uuid NOT NULL,
    report_id uuid NOT NULL,
    user_id bigint NOT NULL,
    author_name character varying(255) NOT NULL,
    text text NOT NULL,
    is_internal boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ovr_reportable_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ovr_reportable_types (
    id uuid NOT NULL,
    incident_type_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    name_ar character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    organization_id bigint
);


--
-- Name: ovr_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ovr_settings (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value text,
    type character varying(255) DEFAULT 'string'::character varying NOT NULL,
    description character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ovr_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ovr_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ovr_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ovr_settings_id_seq OWNED BY public.ovr_settings.id;


--
-- Name: ovr_status_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ovr_status_history (
    id uuid NOT NULL,
    report_id uuid NOT NULL,
    from_status character varying(20) NOT NULL,
    to_status character varying(20) NOT NULL,
    changed_by bigint NOT NULL,
    reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: permission_audits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permission_audits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permission_audits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permission_audits_id_seq OWNED BY public.authorization_assignment_audits.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: portfolios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.portfolios (
    id bigint NOT NULL,
    code character varying(20) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    rationale text,
    start_date date,
    end_date date,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    "order" smallint DEFAULT '0'::smallint NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    strategic_plan_link character varying(255),
    directive_source character varying(255),
    directive_source_other character varying(255),
    priority_rank integer DEFAULT 0 NOT NULL,
    weight numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    portfolio_status character varying(50) DEFAULT 'active'::character varying NOT NULL,
    portfolio_progress numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    organization_id bigint,
    CONSTRAINT strategic_directions_directive_source_check CHECK (((directive_source)::text = ANY (ARRAY[('cluster_3'::character varying)::text, ('moh'::character varying)::text, ('holding'::character varying)::text, ('other'::character varying)::text]))),
    CONSTRAINT strategic_directions_status_check CHECK (((status)::text = ANY (ARRAY[('draft'::character varying)::text, ('active'::character varying)::text, ('completed'::character varying)::text, ('cancelled'::character varying)::text])))
);


--
-- Name: project_expenses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.project_expenses (
    id bigint NOT NULL,
    project_id bigint NOT NULL,
    task_id bigint,
    created_by bigint NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    amount numeric(15,2) NOT NULL,
    category character varying(255) DEFAULT 'other'::character varying NOT NULL,
    expense_date date NOT NULL,
    reference_number character varying(255),
    attachment_path character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    original_amount numeric(15,2),
    deleted_at timestamp(0) without time zone,
    is_finalized boolean DEFAULT false NOT NULL,
    finalized_at timestamp(0) without time zone,
    finalized_by bigint,
    CONSTRAINT project_expenses_category_check CHECK (((category)::text = ANY (ARRAY[('human_resources'::character varying)::text, ('materials'::character varying)::text, ('services'::character varying)::text, ('operational'::character varying)::text, ('travel'::character varying)::text, ('training'::character varying)::text, ('other'::character varying)::text])))
);


--
-- Name: project_expenses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.project_expenses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: project_expenses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.project_expenses_id_seq OWNED BY public.project_expenses.id;


--
-- Name: project_risks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.project_risks (
    id bigint NOT NULL,
    project_id bigint NOT NULL,
    risk character varying(255) NOT NULL,
    probability character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    impact character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    response text,
    status character varying(255) DEFAULT 'identified'::character varying NOT NULL,
    "order" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT project_risks_impact_check CHECK (((impact)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text]))),
    CONSTRAINT project_risks_probability_check CHECK (((probability)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text]))),
    CONSTRAINT project_risks_status_check CHECK (((status)::text = ANY (ARRAY[('open'::character varying)::text, ('mitigated'::character varying)::text, ('closed'::character varying)::text])))
);


--
-- Name: project_risks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.project_risks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: project_risks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.project_risks_id_seq OWNED BY public.project_risks.id;


--
-- Name: project_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.project_settings (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value text,
    type character varying(255) DEFAULT 'string'::character varying NOT NULL,
    description character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: project_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.project_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: project_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.project_settings_id_seq OWNED BY public.project_settings.id;


--
-- Name: projects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.projects (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(255),
    description text,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    start_date date,
    end_date date,
    actual_start_date date,
    actual_end_date date,
    progress numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    budget numeric(15,2),
    actual_cost numeric(15,2),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    objectives json,
    in_scope json,
    out_of_scope json,
    human_resources text,
    technical_resources text,
    financial_resources text,
    created_by bigint,
    department_id bigint,
    spent_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    organization_id bigint,
    program_id bigint,
    type character varying(255) DEFAULT 'development'::character varying,
    triage_answers json,
    business_case text,
    success_criteria json,
    requirements json,
    manager_authority json,
    approval_criteria text,
    exit_criteria text,
    problem_statement text,
    target_process text,
    root_cause text,
    expected_benefits json,
    current_pdca_phase character varying(255),
    lessons_learned text,
    outcome_summary text,
    sustainability_plan text,
    achievement_percentage numeric(5,2),
    achievement_status character varying(255),
    CONSTRAINT projects_priority_check CHECK (((priority)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('urgent'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT projects_status_check CHECK (((status)::text = ANY (ARRAY[('draft'::character varying)::text, ('planning'::character varying)::text, ('in_progress'::character varying)::text, ('on_hold'::character varying)::text, ('completed'::character varying)::text, ('cancelled'::character varying)::text])))
);


--
-- Name: projects_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.projects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: projects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.projects_id_seq OWNED BY public.projects.id;


--
-- Name: recommendations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recommendations (
    id bigint NOT NULL,
    reference_number character varying(20),
    title character varying(255) NOT NULL,
    description text,
    priority character varying(20) DEFAULT 'medium'::character varying NOT NULL,
    status character varying(20) DEFAULT 'proposed'::character varying NOT NULL,
    assignee_id bigint,
    due_date date,
    completed_at timestamp(0) without time zone,
    overdue_notified_at timestamp(0) without time zone,
    organization_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    meeting_id bigint,
    decidable_type character varying(255),
    decidable_id bigint,
    kind character varying(20) DEFAULT 'action_item'::character varying NOT NULL,
    type character varying(40),
    requested_by bigint,
    made_by bigint,
    decision_date date,
    effective_date date,
    impact text,
    rationale text,
    defer_reason character varying(5000),
    deferred_until timestamp(0) without time zone,
    deferred_by bigint,
    deferred_at timestamp(0) without time zone,
    CONSTRAINT recommendations_kind_check CHECK (((kind)::text = ANY (ARRAY[('ruling'::character varying)::text, ('action_item'::character varying)::text]))),
    CONSTRAINT recommendations_priority_check CHECK (((priority)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT recommendations_status_check CHECK (((status)::text = ANY (ARRAY[('proposed'::character varying)::text, ('pending'::character varying)::text, ('accepted'::character varying)::text, ('approved'::character varying)::text, ('rejected'::character varying)::text, ('deferred'::character varying)::text, ('completed'::character varying)::text])))
);


--
-- Name: recommendations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.recommendations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: recommendations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.recommendations_id_seq OWNED BY public.recommendations.id;


--
-- Name: resolution_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.resolution_links (
    id bigint NOT NULL,
    resolution_id bigint NOT NULL,
    linkable_type character varying(50) NOT NULL,
    linkable_id bigint NOT NULL,
    link_role character varying(30) DEFAULT 'related_to'::character varying NOT NULL,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT resolution_links_link_role_check CHECK (((link_role)::text = ANY (ARRAY[('related_to'::character varying)::text, ('implementation_scope'::character varying)::text]))),
    CONSTRAINT resolution_links_linkable_type_check CHECK (((linkable_type)::text = ANY (ARRAY[('project'::character varying)::text, ('risk'::character varying)::text])))
);


--
-- Name: resolution_links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.resolution_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: resolution_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.resolution_links_id_seq OWNED BY public.resolution_links.id;


--
-- Name: reviews; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reviews (
    id bigint NOT NULL,
    title character varying(255) NOT NULL,
    reviewable_type character varying(255) NOT NULL,
    reviewable_id bigint NOT NULL,
    type character varying(255) DEFAULT 'quarterly'::character varying NOT NULL,
    pdca_phase character varying(255) DEFAULT 'check'::character varying NOT NULL,
    review_date date NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    progress_snapshot numeric(5,2),
    overall_status character varying(255) DEFAULT 'on_track'::character varying NOT NULL,
    achievements text,
    challenges text,
    lessons_learned text,
    next_steps text,
    recommendations text,
    conducted_by bigint,
    attendees json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    organization_id bigint,
    CONSTRAINT reviews_overall_status_check CHECK (((overall_status)::text = ANY (ARRAY[('on_track'::character varying)::text, ('at_risk'::character varying)::text, ('off_track'::character varying)::text, ('completed'::character varying)::text]))),
    CONSTRAINT reviews_pdca_phase_check CHECK (((pdca_phase)::text = ANY (ARRAY[('plan'::character varying)::text, ('do'::character varying)::text, ('check'::character varying)::text, ('act'::character varying)::text]))),
    CONSTRAINT reviews_type_check CHECK (((type)::text = ANY (ARRAY[('monthly'::character varying)::text, ('quarterly'::character varying)::text, ('annual'::character varying)::text, ('adhoc'::character varying)::text])))
);


--
-- Name: reviews_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.reviews_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: reviews_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.reviews_id_seq OWNED BY public.reviews.id;


--
-- Name: risk_action_updates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.risk_action_updates (
    id bigint NOT NULL,
    risk_action_id bigint NOT NULL,
    organization_id bigint,
    user_id bigint,
    progress_pct smallint,
    status character varying(20),
    notes text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT risk_action_updates_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('in_progress'::character varying)::text, ('completed'::character varying)::text, ('blocked'::character varying)::text, ('cancelled'::character varying)::text])))
);


--
-- Name: risk_action_updates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.risk_action_updates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_action_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.risk_action_updates_id_seq OWNED BY public.risk_action_updates.id;


--
-- Name: risk_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.risk_actions (
    id bigint NOT NULL,
    risk_id bigint NOT NULL,
    organization_id bigint,
    title character varying(255) NOT NULL,
    type character varying(20) NOT NULL,
    description text,
    owner_id bigint,
    due_date date,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    progress_pct smallint DEFAULT '0'::smallint NOT NULL,
    notes text,
    overdue_notified_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT risk_actions_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('in_progress'::character varying)::text, ('completed'::character varying)::text, ('blocked'::character varying)::text, ('cancelled'::character varying)::text]))),
    CONSTRAINT risk_actions_type_check CHECK (((type)::text = ANY (ARRAY[('preventive'::character varying)::text, ('corrective'::character varying)::text])))
);


--
-- Name: risk_actions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.risk_actions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_actions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.risk_actions_id_seq OWNED BY public.risk_actions.id;


--
-- Name: risk_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.risk_alerts (
    id bigint NOT NULL,
    risk_id bigint,
    risk_action_id bigint,
    risk_assessment_id bigint,
    organization_id bigint,
    type character varying(40) NOT NULL,
    payload json,
    sent_to bigint,
    sent_at timestamp(0) without time zone,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT risk_alerts_type_check CHECK (((type)::text = ANY (ARRAY[('review_due'::character varying)::text, ('level_escalated'::character varying)::text, ('action_overdue'::character varying)::text])))
);


--
-- Name: risk_alerts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.risk_alerts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_alerts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.risk_alerts_id_seq OWNED BY public.risk_alerts.id;


--
-- Name: risk_assessments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.risk_assessments (
    id bigint NOT NULL,
    risk_id bigint NOT NULL,
    organization_id bigint,
    likelihood smallint NOT NULL,
    impact smallint NOT NULL,
    score smallint NOT NULL,
    level character varying(20) NOT NULL,
    residual_likelihood smallint,
    residual_impact smallint,
    residual_score smallint,
    residual_level character varying(20),
    assessor_id bigint,
    notes text,
    next_review_at date,
    review_due_notified_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT risk_assessments_level_check CHECK (((level)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT risk_assessments_residual_level_check CHECK (((residual_level)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('critical'::character varying)::text])))
);


--
-- Name: risk_assessments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.risk_assessments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_assessments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.risk_assessments_id_seq OWNED BY public.risk_assessments.id;


--
-- Name: risk_impact_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.risk_impact_types (
    id bigint NOT NULL,
    value character varying(30) NOT NULL,
    label character varying(100) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: risk_impact_types_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.risk_impact_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_impact_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.risk_impact_types_id_seq OWNED BY public.risk_impact_types.id;


--
-- Name: risk_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.risk_settings (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value text,
    type character varying(255) DEFAULT 'string'::character varying NOT NULL,
    description character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: risk_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.risk_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.risk_settings_id_seq OWNED BY public.risk_settings.id;


--
-- Name: risk_status_changes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.risk_status_changes (
    id bigint NOT NULL,
    risk_id bigint NOT NULL,
    organization_id bigint,
    from_status character varying(20),
    to_status character varying(20) NOT NULL,
    changed_by bigint,
    reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT risk_status_changes_from_status_check CHECK (((from_status)::text = ANY (ARRAY[('open'::character varying)::text, ('treating'::character varying)::text, ('closed'::character varying)::text, ('accepted'::character varying)::text]))),
    CONSTRAINT risk_status_changes_to_status_check CHECK (((to_status)::text = ANY (ARRAY[('open'::character varying)::text, ('treating'::character varying)::text, ('closed'::character varying)::text, ('accepted'::character varying)::text])))
);


--
-- Name: risk_status_changes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.risk_status_changes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_status_changes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.risk_status_changes_id_seq OWNED BY public.risk_status_changes.id;


--
-- Name: risk_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.risk_types (
    id bigint NOT NULL,
    value character varying(30) NOT NULL,
    label character varying(100) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: risk_types_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.risk_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risk_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.risk_types_id_seq OWNED BY public.risk_types.id;


--
-- Name: risks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.risks (
    id bigint NOT NULL,
    code character varying(20) NOT NULL,
    organization_id bigint,
    title character varying(255) NOT NULL,
    discovery_date date NOT NULL,
    type character varying(30) NOT NULL,
    department_id bigint,
    description text,
    initial_likelihood smallint NOT NULL,
    initial_impact smallint NOT NULL,
    current_likelihood smallint NOT NULL,
    current_impact smallint NOT NULL,
    current_score smallint NOT NULL,
    current_level character varying(20) NOT NULL,
    status character varying(20) DEFAULT 'open'::character varying NOT NULL,
    owner_id bigint,
    stakeholder_ids json,
    preventive_measures text,
    target_close_date date,
    response_type character varying(20) NOT NULL,
    riskable_type character varying(255),
    riskable_id bigint,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    consequences text,
    impact_details json,
    CONSTRAINT risks_current_level_check CHECK (((current_level)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT risks_response_type_check CHECK (((response_type)::text = ANY (ARRAY[('avoid'::character varying)::text, ('mitigate'::character varying)::text, ('transfer'::character varying)::text, ('accept'::character varying)::text]))),
    CONSTRAINT risks_status_check CHECK (((status)::text = ANY (ARRAY[('open'::character varying)::text, ('treating'::character varying)::text, ('closed'::character varying)::text, ('accepted'::character varying)::text])))
);


--
-- Name: risks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.risks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: risks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.risks_id_seq OWNED BY public.risks.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: stakeholders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stakeholders (
    id bigint NOT NULL,
    project_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255),
    phone character varying(255),
    organization character varying(255),
    role character varying(255) DEFAULT 'other'::character varying NOT NULL,
    influence character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    interest character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id bigint,
    CONSTRAINT stakeholders_influence_check CHECK (((influence)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text]))),
    CONSTRAINT stakeholders_interest_check CHECK (((interest)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text]))),
    CONSTRAINT stakeholders_role_check CHECK (((role)::text = ANY (ARRAY[('end_user'::character varying)::text, ('implementer'::character varying)::text, ('consultant'::character varying)::text, ('governance'::character varying)::text, ('operations'::character varying)::text, ('influencer'::character varying)::text, ('other'::character varying)::text])))
);


--
-- Name: stakeholders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.stakeholders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stakeholders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.stakeholders_id_seq OWNED BY public.stakeholders.id;


--
-- Name: strategic_directions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.strategic_directions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: strategic_directions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.strategic_directions_id_seq OWNED BY public.portfolios.id;


--
-- Name: survey_answer_files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.survey_answer_files (
    id bigint NOT NULL,
    answer_id bigint NOT NULL,
    file_path character varying(255) NOT NULL,
    original_name character varying(255) NOT NULL,
    mime_type character varying(100),
    size bigint DEFAULT '0'::bigint NOT NULL,
    uploaded_at timestamp(0) without time zone NOT NULL
);


--
-- Name: survey_answer_files_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.survey_answer_files_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: survey_answer_files_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.survey_answer_files_id_seq OWNED BY public.survey_answer_files.id;


--
-- Name: survey_field_answers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.survey_field_answers (
    id bigint NOT NULL,
    response_id bigint NOT NULL,
    field_id bigint NOT NULL,
    field_key character varying(100) NOT NULL,
    answer_value json,
    answer_text text,
    answer_number numeric(20,4),
    answer_date date,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: survey_field_answers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.survey_field_answers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: survey_field_answers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.survey_field_answers_id_seq OWNED BY public.survey_field_answers.id;


--
-- Name: survey_fields; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.survey_fields (
    id bigint NOT NULL,
    survey_id bigint NOT NULL,
    section_id bigint,
    field_key character varying(100) NOT NULL,
    name character varying(100) NOT NULL,
    label character varying(255) NOT NULL,
    description text,
    type character varying(30) NOT NULL,
    config json,
    is_required boolean DEFAULT false NOT NULL,
    "order" integer DEFAULT 0 NOT NULL,
    is_visible boolean DEFAULT true NOT NULL,
    visibility_rules json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: survey_fields_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.survey_fields_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: survey_fields_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.survey_fields_id_seq OWNED BY public.survey_fields.id;


--
-- Name: survey_invitations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.survey_invitations (
    id bigint NOT NULL,
    survey_id bigint NOT NULL,
    token character varying(64) NOT NULL,
    email character varying(255),
    name character varying(255),
    department_id bigint,
    user_id bigint,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    expires_at timestamp(0) without time zone,
    max_uses integer DEFAULT 1 NOT NULL,
    used_count integer DEFAULT 0 NOT NULL,
    revoked_at timestamp(0) without time zone,
    opened_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    response_id bigint,
    sent_at timestamp(0) without time zone,
    reminded_at timestamp(0) without time zone,
    reminder_count integer DEFAULT 0 NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: survey_invitations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.survey_invitations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: survey_invitations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.survey_invitations_id_seq OWNED BY public.survey_invitations.id;


--
-- Name: survey_responses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.survey_responses (
    id bigint NOT NULL,
    survey_id bigint NOT NULL,
    survey_version_id bigint,
    respondent_type character varying(20) DEFAULT 'public'::character varying NOT NULL,
    respondent_id bigint,
    respondent_name text,
    respondent_email character varying(255),
    respondent_phone character varying(30),
    invitation_id bigint,
    status character varying(20) DEFAULT 'submitted'::character varying NOT NULL,
    ip_hash character varying(64),
    fingerprint_hash character varying(64),
    user_agent text,
    completion_time integer,
    consented_at timestamp(0) without time zone,
    submitted_at timestamp(0) without time zone,
    reviewed_at timestamp(0) without time zone,
    reviewed_by bigint,
    reviewer_notes text,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    answers_snapshot json,
    respondent_organization_id bigint
);


--
-- Name: survey_responses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.survey_responses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: survey_responses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.survey_responses_id_seq OWNED BY public.survey_responses.id;


--
-- Name: survey_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.survey_sections (
    id bigint NOT NULL,
    survey_id bigint NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    "order" integer DEFAULT 0 NOT NULL,
    is_visible boolean DEFAULT true NOT NULL,
    visibility_rules json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: survey_sections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.survey_sections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: survey_sections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.survey_sections_id_seq OWNED BY public.survey_sections.id;


--
-- Name: survey_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.survey_versions (
    id bigint NOT NULL,
    survey_id bigint NOT NULL,
    version_hash character varying(64) NOT NULL,
    snapshot_json json NOT NULL,
    fields_count integer DEFAULT 0 NOT NULL,
    sections_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone NOT NULL
);


--
-- Name: survey_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.survey_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: survey_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.survey_versions_id_seq OWNED BY public.survey_versions.id;


--
-- Name: surveys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.surveys (
    id bigint NOT NULL,
    code character varying(50) NOT NULL,
    organization_id bigint,
    canonical_id bigint,
    revision integer DEFAULT 1 NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    type character varying(20) DEFAULT 'initial'::character varying NOT NULL,
    category character varying(50),
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    is_public boolean DEFAULT true NOT NULL,
    requires_auth boolean DEFAULT false NOT NULL,
    accepting_responses boolean DEFAULT true NOT NULL,
    allow_multiple_responses boolean DEFAULT false NOT NULL,
    allow_edit_response boolean DEFAULT false NOT NULL,
    starts_at timestamp(0) without time zone,
    ends_at timestamp(0) without time zone,
    published_at timestamp(0) without time zone,
    locked_at timestamp(0) without time zone,
    closed_at timestamp(0) without time zone,
    close_reason character varying(255),
    consent_text text,
    consent_required boolean DEFAULT false NOT NULL,
    welcome_message text,
    thank_you_message text,
    settings json,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    privacy_mode character varying(20) DEFAULT 'identified'::character varying NOT NULL,
    department_id bigint,
    CONSTRAINT surveys_privacy_mode_check CHECK (((privacy_mode)::text = ANY (ARRAY[('identified'::character varying)::text, ('confidential'::character varying)::text, ('anonymous'::character varying)::text])))
);


--
-- Name: surveys_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.surveys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: surveys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.surveys_id_seq OWNED BY public.surveys.id;


--
-- Name: system_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_settings (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    name_en character varying(255),
    code character varying(255),
    region character varying(255),
    city character varying(255),
    address character varying(255),
    phone character varying(255),
    email character varying(255),
    website character varying(255),
    logo character varying(255),
    settings json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: system_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.system_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: system_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.system_settings_id_seq OWNED BY public.system_settings.id;


--
-- Name: tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tasks (
    id bigint NOT NULL,
    project_id bigint,
    milestone_id bigint,
    parent_id bigint,
    assigned_to bigint,
    created_by bigint,
    title character varying(255) NOT NULL,
    description text,
    status character varying(255) DEFAULT 'todo'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    start_date date,
    due_date date,
    completed_date date,
    progress numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    estimated_hours integer,
    actual_hours integer,
    "order" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    type character varying(20) DEFAULT 'project'::character varying NOT NULL,
    owner_id bigint,
    department_id bigint,
    is_private boolean DEFAULT false NOT NULL,
    recurrence_rule character varying(255),
    recurring_parent_id bigint,
    next_occurrence date,
    challenges text,
    lessons_learned text,
    status_comment text,
    organization_id bigint,
    source_type character varying(255),
    source_id bigint,
    source_sensitivity character varying(255),
    CONSTRAINT tasks_priority_check CHECK (((priority)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('urgent'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT tasks_status_check CHECK (((status)::text = ANY (ARRAY['todo'::text, 'in_progress'::text, 'in_review'::text, 'completed'::text, 'cancelled'::text, 'on_hold'::text])))
);


--
-- Name: tasks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tasks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tasks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tasks_id_seq OWNED BY public.tasks.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    phone character varying(255),
    job_title character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    extension character varying(10),
    department_id bigint,
    created_by bigint,
    updated_by bigint,
    organization_id bigint,
    locked_until timestamp(0) without time zone,
    failed_login_attempts integer DEFAULT 0 NOT NULL,
    last_failed_login_at timestamp(0) without time zone,
    last_login_at timestamp(0) without time zone,
    last_login_ip character varying(45),
    two_factor_secret text,
    two_factor_recovery_codes text,
    two_factor_confirmed_at timestamp(0) without time zone,
    two_factor_required boolean DEFAULT false NOT NULL,
    preferred_locale character varying(5),
    deleted_at timestamp(0) without time zone,
    two_factor_recovery_code_hashes json,
    registration_status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    registration_approved_at timestamp(0) without time zone,
    registration_approved_by bigint
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: activity_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs ALTER COLUMN id SET DEFAULT nextval('public.activity_logs_id_seq'::regclass);


--
-- Name: archived_strategic_objectives id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.archived_strategic_objectives ALTER COLUMN id SET DEFAULT nextval('public.archived_strategic_objectives_id_seq'::regclass);


--
-- Name: attachments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attachments ALTER COLUMN id SET DEFAULT nextval('public.attachments_id_seq'::regclass);


--
-- Name: authorization_assignment_audits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_assignment_audits ALTER COLUMN id SET DEFAULT nextval('public.permission_audits_id_seq'::regclass);


--
-- Name: authorization_decision_audits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_decision_audits ALTER COLUMN id SET DEFAULT nextval('public.authorization_decision_audits_id_seq'::regclass);


--
-- Name: authorization_record_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_record_rules ALTER COLUMN id SET DEFAULT nextval('public.authorization_record_rules_id_seq'::regclass);


--
-- Name: authorization_resources id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_resources ALTER COLUMN id SET DEFAULT nextval('public.authorization_resources_id_seq'::regclass);


--
-- Name: authorization_role_assignments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_role_assignments ALTER COLUMN id SET DEFAULT nextval('public.authorization_role_assignments_id_seq'::regclass);


--
-- Name: authorization_roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_roles ALTER COLUMN id SET DEFAULT nextval('public.authorization_roles_id_seq'::regclass);


--
-- Name: blockers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blockers ALTER COLUMN id SET DEFAULT nextval('public.blockers_id_seq'::regclass);


--
-- Name: comments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comments ALTER COLUMN id SET DEFAULT nextval('public.comments_id_seq'::regclass);


--
-- Name: data_import_requests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_import_requests ALTER COLUMN id SET DEFAULT nextval('public.data_import_requests_id_seq'::regclass);


--
-- Name: data_mapping_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_mapping_templates ALTER COLUMN id SET DEFAULT nextval('public.data_mapping_templates_id_seq'::regclass);


--
-- Name: department_capacity_roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_capacity_roles ALTER COLUMN id SET DEFAULT nextval('public.department_capacity_roles_id_seq'::regclass);


--
-- Name: departments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments ALTER COLUMN id SET DEFAULT nextval('public.departments_id_seq'::regclass);


--
-- Name: email_otps id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_otps ALTER COLUMN id SET DEFAULT nextval('public.email_otps_id_seq'::regclass);


--
-- Name: employee_certificates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_certificates ALTER COLUMN id SET DEFAULT nextval('public.employee_certificates_id_seq'::regclass);


--
-- Name: employee_personal_info id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_personal_info ALTER COLUMN id SET DEFAULT nextval('public.employee_personal_info_id_seq'::regclass);


--
-- Name: employee_profiles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles ALTER COLUMN id SET DEFAULT nextval('public.employee_profiles_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: governance_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.governance_rules ALTER COLUMN id SET DEFAULT nextval('public.governance_rules_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: kpi_links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_links ALTER COLUMN id SET DEFAULT nextval('public.kpi_links_id_seq'::regclass);


--
-- Name: kpi_measurements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_measurements ALTER COLUMN id SET DEFAULT nextval('public.kpi_measurements_id_seq'::regclass);


--
-- Name: kpis id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpis ALTER COLUMN id SET DEFAULT nextval('public.kpis_id_seq'::regclass);


--
-- Name: login_attempts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_attempts ALTER COLUMN id SET DEFAULT nextval('public.login_attempts_id_seq'::regclass);


--
-- Name: meeting_agenda_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_agenda_items ALTER COLUMN id SET DEFAULT nextval('public.meeting_agenda_items_id_seq'::regclass);


--
-- Name: meeting_categories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_categories ALTER COLUMN id SET DEFAULT nextval('public.meeting_categories_id_seq'::regclass);


--
-- Name: meeting_resolutions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_resolutions ALTER COLUMN id SET DEFAULT nextval('public.meeting_resolutions_id_seq'::regclass);


--
-- Name: meeting_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_settings ALTER COLUMN id SET DEFAULT nextval('public.meeting_settings_id_seq'::regclass);


--
-- Name: meetings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meetings ALTER COLUMN id SET DEFAULT nextval('public.meetings_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: milestone_deliverables id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.milestone_deliverables ALTER COLUMN id SET DEFAULT nextval('public.milestone_deliverables_id_seq'::regclass);


--
-- Name: milestones id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.milestones ALTER COLUMN id SET DEFAULT nextval('public.milestones_id_seq'::regclass);


--
-- Name: organizations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations ALTER COLUMN id SET DEFAULT nextval('public.organizations_id_seq'::regclass);


--
-- Name: ovr_incident_participants id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_participants ALTER COLUMN id SET DEFAULT nextval('public.ovr_incident_participants_id_seq'::regclass);


--
-- Name: ovr_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_settings ALTER COLUMN id SET DEFAULT nextval('public.ovr_settings_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: portfolios id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.portfolios ALTER COLUMN id SET DEFAULT nextval('public.strategic_directions_id_seq'::regclass);


--
-- Name: programs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs ALTER COLUMN id SET DEFAULT nextval('public.initiatives_id_seq'::regclass);


--
-- Name: project_expenses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_expenses ALTER COLUMN id SET DEFAULT nextval('public.project_expenses_id_seq'::regclass);


--
-- Name: project_risks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_risks ALTER COLUMN id SET DEFAULT nextval('public.project_risks_id_seq'::regclass);


--
-- Name: project_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_settings ALTER COLUMN id SET DEFAULT nextval('public.project_settings_id_seq'::regclass);


--
-- Name: projects id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects ALTER COLUMN id SET DEFAULT nextval('public.projects_id_seq'::regclass);


--
-- Name: recommendations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendations ALTER COLUMN id SET DEFAULT nextval('public.recommendations_id_seq'::regclass);


--
-- Name: resolution_links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resolution_links ALTER COLUMN id SET DEFAULT nextval('public.resolution_links_id_seq'::regclass);


--
-- Name: reviews id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reviews ALTER COLUMN id SET DEFAULT nextval('public.reviews_id_seq'::regclass);


--
-- Name: risk_action_updates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_action_updates ALTER COLUMN id SET DEFAULT nextval('public.risk_action_updates_id_seq'::regclass);


--
-- Name: risk_actions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_actions ALTER COLUMN id SET DEFAULT nextval('public.risk_actions_id_seq'::regclass);


--
-- Name: risk_alerts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_alerts ALTER COLUMN id SET DEFAULT nextval('public.risk_alerts_id_seq'::regclass);


--
-- Name: risk_assessments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_assessments ALTER COLUMN id SET DEFAULT nextval('public.risk_assessments_id_seq'::regclass);


--
-- Name: risk_impact_types id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_impact_types ALTER COLUMN id SET DEFAULT nextval('public.risk_impact_types_id_seq'::regclass);


--
-- Name: risk_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_settings ALTER COLUMN id SET DEFAULT nextval('public.risk_settings_id_seq'::regclass);


--
-- Name: risk_status_changes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_status_changes ALTER COLUMN id SET DEFAULT nextval('public.risk_status_changes_id_seq'::regclass);


--
-- Name: risk_types id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_types ALTER COLUMN id SET DEFAULT nextval('public.risk_types_id_seq'::regclass);


--
-- Name: risks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risks ALTER COLUMN id SET DEFAULT nextval('public.risks_id_seq'::regclass);


--
-- Name: stakeholders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stakeholders ALTER COLUMN id SET DEFAULT nextval('public.stakeholders_id_seq'::regclass);


--
-- Name: survey_answer_files id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_answer_files ALTER COLUMN id SET DEFAULT nextval('public.survey_answer_files_id_seq'::regclass);


--
-- Name: survey_field_answers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_field_answers ALTER COLUMN id SET DEFAULT nextval('public.survey_field_answers_id_seq'::regclass);


--
-- Name: survey_fields id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_fields ALTER COLUMN id SET DEFAULT nextval('public.survey_fields_id_seq'::regclass);


--
-- Name: survey_invitations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_invitations ALTER COLUMN id SET DEFAULT nextval('public.survey_invitations_id_seq'::regclass);


--
-- Name: survey_responses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_responses ALTER COLUMN id SET DEFAULT nextval('public.survey_responses_id_seq'::regclass);


--
-- Name: survey_sections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_sections ALTER COLUMN id SET DEFAULT nextval('public.survey_sections_id_seq'::regclass);


--
-- Name: survey_versions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_versions ALTER COLUMN id SET DEFAULT nextval('public.survey_versions_id_seq'::regclass);


--
-- Name: surveys id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.surveys ALTER COLUMN id SET DEFAULT nextval('public.surveys_id_seq'::regclass);


--
-- Name: system_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings ALTER COLUMN id SET DEFAULT nextval('public.system_settings_id_seq'::regclass);


--
-- Name: tasks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks ALTER COLUMN id SET DEFAULT nextval('public.tasks_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: activity_logs activity_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs
    ADD CONSTRAINT activity_logs_pkey PRIMARY KEY (id);


--
-- Name: archived_strategic_objectives archived_strategic_objectives_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.archived_strategic_objectives
    ADD CONSTRAINT archived_strategic_objectives_pkey PRIMARY KEY (id);


--
-- Name: attachments attachments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attachments
    ADD CONSTRAINT attachments_pkey PRIMARY KEY (id);


--
-- Name: authorization_decision_audits authorization_decision_audits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_decision_audits
    ADD CONSTRAINT authorization_decision_audits_pkey PRIMARY KEY (id);


--
-- Name: authorization_record_rules authorization_record_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_record_rules
    ADD CONSTRAINT authorization_record_rules_pkey PRIMARY KEY (id);


--
-- Name: authorization_resources authorization_resources_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_resources
    ADD CONSTRAINT authorization_resources_key_unique UNIQUE (key);


--
-- Name: authorization_resources authorization_resources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_resources
    ADD CONSTRAINT authorization_resources_pkey PRIMARY KEY (id);


--
-- Name: authorization_role_assignments authorization_role_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_role_assignments
    ADD CONSTRAINT authorization_role_assignments_pkey PRIMARY KEY (id);


--
-- Name: authorization_role_permissions authorization_role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_role_permissions
    ADD CONSTRAINT authorization_role_permissions_pkey PRIMARY KEY (authorization_role_id, authorization_resource_id, action);


--
-- Name: authorization_roles authorization_roles_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_roles
    ADD CONSTRAINT authorization_roles_name_unique UNIQUE (name);


--
-- Name: authorization_roles authorization_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_roles
    ADD CONSTRAINT authorization_roles_pkey PRIMARY KEY (id);


--
-- Name: blockers blockers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blockers
    ADD CONSTRAINT blockers_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: comments comments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comments
    ADD CONSTRAINT comments_pkey PRIMARY KEY (id);


--
-- Name: data_import_requests data_import_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_import_requests
    ADD CONSTRAINT data_import_requests_pkey PRIMARY KEY (id);


--
-- Name: data_mapping_templates data_mapping_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_mapping_templates
    ADD CONSTRAINT data_mapping_templates_pkey PRIMARY KEY (id);


--
-- Name: department_capacity_roles department_capacity_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_capacity_roles
    ADD CONSTRAINT department_capacity_roles_pkey PRIMARY KEY (id);


--
-- Name: departments departments_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_code_unique UNIQUE (code);


--
-- Name: departments departments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_pkey PRIMARY KEY (id);


--
-- Name: email_otps email_otps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_otps
    ADD CONSTRAINT email_otps_pkey PRIMARY KEY (id);


--
-- Name: employee_certificates employee_certificates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_certificates
    ADD CONSTRAINT employee_certificates_pkey PRIMARY KEY (id);


--
-- Name: employee_personal_info employee_personal_info_employee_profile_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_personal_info
    ADD CONSTRAINT employee_personal_info_employee_profile_id_unique UNIQUE (employee_profile_id);


--
-- Name: employee_personal_info employee_personal_info_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_personal_info
    ADD CONSTRAINT employee_personal_info_pkey PRIMARY KEY (id);


--
-- Name: employee_profiles employee_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles
    ADD CONSTRAINT employee_profiles_pkey PRIMARY KEY (id);


--
-- Name: employee_profiles employee_profiles_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles
    ADD CONSTRAINT employee_profiles_user_id_unique UNIQUE (user_id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: governance_rules governance_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.governance_rules
    ADD CONSTRAINT governance_rules_pkey PRIMARY KEY (id);


--
-- Name: governance_rules governance_rules_scope_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.governance_rules
    ADD CONSTRAINT governance_rules_scope_unique UNIQUE (organization_id, resource_type, resource_subtype);


--
-- Name: programs initiatives_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs
    ADD CONSTRAINT initiatives_code_unique UNIQUE (code);


--
-- Name: programs initiatives_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs
    ADD CONSTRAINT initiatives_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: kpi_links kpi_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_links
    ADD CONSTRAINT kpi_links_pkey PRIMARY KEY (id);


--
-- Name: kpi_measurements kpi_measurements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_measurements
    ADD CONSTRAINT kpi_measurements_pkey PRIMARY KEY (id);


--
-- Name: kpis kpis_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpis
    ADD CONSTRAINT kpis_code_unique UNIQUE (code);


--
-- Name: kpis kpis_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpis
    ADD CONSTRAINT kpis_pkey PRIMARY KEY (id);


--
-- Name: login_attempts login_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_attempts
    ADD CONSTRAINT login_attempts_pkey PRIMARY KEY (id);


--
-- Name: meeting_agenda_items meeting_agenda_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_agenda_items
    ADD CONSTRAINT meeting_agenda_items_pkey PRIMARY KEY (id);


--
-- Name: meeting_attendees meeting_attendees_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_attendees
    ADD CONSTRAINT meeting_attendees_pkey PRIMARY KEY (meeting_id, user_id);


--
-- Name: meeting_categories meeting_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_categories
    ADD CONSTRAINT meeting_categories_pkey PRIMARY KEY (id);


--
-- Name: meeting_resolutions meeting_resolutions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_resolutions
    ADD CONSTRAINT meeting_resolutions_pkey PRIMARY KEY (id);


--
-- Name: meeting_settings meeting_settings_org_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_settings
    ADD CONSTRAINT meeting_settings_org_unique UNIQUE (organization_id);


--
-- Name: meeting_settings meeting_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_settings
    ADD CONSTRAINT meeting_settings_pkey PRIMARY KEY (id);


--
-- Name: meetings meetings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meetings
    ADD CONSTRAINT meetings_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: milestone_deliverables milestone_deliverables_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.milestone_deliverables
    ADD CONSTRAINT milestone_deliverables_pkey PRIMARY KEY (id);


--
-- Name: milestones milestones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.milestones
    ADD CONSTRAINT milestones_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: organizations organizations_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_code_unique UNIQUE (code);


--
-- Name: organizations organizations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_pkey PRIMARY KEY (id);


--
-- Name: ovr_incident_participants ovr_incident_participants_incident_report_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_participants
    ADD CONSTRAINT ovr_incident_participants_incident_report_id_user_id_unique UNIQUE (incident_report_id, user_id);


--
-- Name: ovr_incident_participants ovr_incident_participants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_participants
    ADD CONSTRAINT ovr_incident_participants_pkey PRIMARY KEY (id);


--
-- Name: ovr_incident_reports ovr_incident_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_pkey PRIMARY KEY (id);


--
-- Name: ovr_incident_reports ovr_incident_reports_report_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_report_number_unique UNIQUE (report_number);


--
-- Name: ovr_incident_reports ovr_incident_reports_tracking_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_tracking_token_unique UNIQUE (tracking_token);


--
-- Name: ovr_incident_types ovr_incident_types_organization_id_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_types
    ADD CONSTRAINT ovr_incident_types_organization_id_name_unique UNIQUE (organization_id, name);


--
-- Name: ovr_incident_types ovr_incident_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_types
    ADD CONSTRAINT ovr_incident_types_pkey PRIMARY KEY (id);


--
-- Name: ovr_report_comments ovr_report_comments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_report_comments
    ADD CONSTRAINT ovr_report_comments_pkey PRIMARY KEY (id);


--
-- Name: ovr_reportable_types ovr_reportable_types_organization_id_incident_type_id_name_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_reportable_types
    ADD CONSTRAINT ovr_reportable_types_organization_id_incident_type_id_name_uniq UNIQUE (organization_id, incident_type_id, name);


--
-- Name: ovr_reportable_types ovr_reportable_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_reportable_types
    ADD CONSTRAINT ovr_reportable_types_pkey PRIMARY KEY (id);


--
-- Name: ovr_settings ovr_settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_settings
    ADD CONSTRAINT ovr_settings_key_unique UNIQUE (key);


--
-- Name: ovr_settings ovr_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_settings
    ADD CONSTRAINT ovr_settings_pkey PRIMARY KEY (id);


--
-- Name: ovr_status_history ovr_status_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_status_history
    ADD CONSTRAINT ovr_status_history_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: authorization_assignment_audits permission_audits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_assignment_audits
    ADD CONSTRAINT permission_audits_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: project_expenses project_expenses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_expenses
    ADD CONSTRAINT project_expenses_pkey PRIMARY KEY (id);


--
-- Name: project_risks project_risks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_risks
    ADD CONSTRAINT project_risks_pkey PRIMARY KEY (id);


--
-- Name: project_settings project_settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_settings
    ADD CONSTRAINT project_settings_key_unique UNIQUE (key);


--
-- Name: project_settings project_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_settings
    ADD CONSTRAINT project_settings_pkey PRIMARY KEY (id);


--
-- Name: projects projects_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_code_unique UNIQUE (code);


--
-- Name: projects projects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_pkey PRIMARY KEY (id);


--
-- Name: recommendations recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendations
    ADD CONSTRAINT recommendations_pkey PRIMARY KEY (id);


--
-- Name: resolution_links resolution_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resolution_links
    ADD CONSTRAINT resolution_links_pkey PRIMARY KEY (id);


--
-- Name: resolution_links resolution_links_unique_combo; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resolution_links
    ADD CONSTRAINT resolution_links_unique_combo UNIQUE (resolution_id, linkable_type, linkable_id, link_role);


--
-- Name: reviews reviews_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_pkey PRIMARY KEY (id);


--
-- Name: risk_action_updates risk_action_updates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_action_updates
    ADD CONSTRAINT risk_action_updates_pkey PRIMARY KEY (id);


--
-- Name: risk_actions risk_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_actions
    ADD CONSTRAINT risk_actions_pkey PRIMARY KEY (id);


--
-- Name: risk_alerts risk_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_alerts
    ADD CONSTRAINT risk_alerts_pkey PRIMARY KEY (id);


--
-- Name: risk_assessments risk_assessments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_assessments
    ADD CONSTRAINT risk_assessments_pkey PRIMARY KEY (id);


--
-- Name: risk_impact_types risk_impact_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_impact_types
    ADD CONSTRAINT risk_impact_types_pkey PRIMARY KEY (id);


--
-- Name: risk_impact_types risk_impact_types_value_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_impact_types
    ADD CONSTRAINT risk_impact_types_value_unique UNIQUE (value);


--
-- Name: risk_settings risk_settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_settings
    ADD CONSTRAINT risk_settings_key_unique UNIQUE (key);


--
-- Name: risk_settings risk_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_settings
    ADD CONSTRAINT risk_settings_pkey PRIMARY KEY (id);


--
-- Name: risk_status_changes risk_status_changes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_status_changes
    ADD CONSTRAINT risk_status_changes_pkey PRIMARY KEY (id);


--
-- Name: risk_types risk_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_types
    ADD CONSTRAINT risk_types_pkey PRIMARY KEY (id);


--
-- Name: risk_types risk_types_value_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_types
    ADD CONSTRAINT risk_types_value_unique UNIQUE (value);


--
-- Name: risks risks_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risks
    ADD CONSTRAINT risks_code_unique UNIQUE (code);


--
-- Name: risks risks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risks
    ADD CONSTRAINT risks_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: stakeholders stakeholders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stakeholders
    ADD CONSTRAINT stakeholders_pkey PRIMARY KEY (id);


--
-- Name: portfolios strategic_directions_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.portfolios
    ADD CONSTRAINT strategic_directions_code_unique UNIQUE (code);


--
-- Name: portfolios strategic_directions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.portfolios
    ADD CONSTRAINT strategic_directions_pkey PRIMARY KEY (id);


--
-- Name: survey_answer_files survey_answer_files_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_answer_files
    ADD CONSTRAINT survey_answer_files_pkey PRIMARY KEY (id);


--
-- Name: survey_field_answers survey_field_answers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_field_answers
    ADD CONSTRAINT survey_field_answers_pkey PRIMARY KEY (id);


--
-- Name: survey_field_answers survey_field_answers_response_id_field_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_field_answers
    ADD CONSTRAINT survey_field_answers_response_id_field_id_unique UNIQUE (response_id, field_id);


--
-- Name: survey_fields survey_fields_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_fields
    ADD CONSTRAINT survey_fields_pkey PRIMARY KEY (id);


--
-- Name: survey_fields survey_fields_survey_id_field_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_fields
    ADD CONSTRAINT survey_fields_survey_id_field_key_unique UNIQUE (survey_id, field_key);


--
-- Name: survey_invitations survey_invitations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_invitations
    ADD CONSTRAINT survey_invitations_pkey PRIMARY KEY (id);


--
-- Name: survey_invitations survey_invitations_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_invitations
    ADD CONSTRAINT survey_invitations_token_unique UNIQUE (token);


--
-- Name: survey_responses survey_responses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_responses
    ADD CONSTRAINT survey_responses_pkey PRIMARY KEY (id);


--
-- Name: survey_sections survey_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_sections
    ADD CONSTRAINT survey_sections_pkey PRIMARY KEY (id);


--
-- Name: survey_versions survey_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_versions
    ADD CONSTRAINT survey_versions_pkey PRIMARY KEY (id);


--
-- Name: survey_versions survey_versions_version_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_versions
    ADD CONSTRAINT survey_versions_version_hash_unique UNIQUE (version_hash);


--
-- Name: surveys surveys_code_revision_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.surveys
    ADD CONSTRAINT surveys_code_revision_unique UNIQUE (code, revision);


--
-- Name: surveys surveys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.surveys
    ADD CONSTRAINT surveys_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_code_unique UNIQUE (code);


--
-- Name: system_settings system_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_pkey PRIMARY KEY (id);


--
-- Name: tasks tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_pkey PRIMARY KEY (id);


--
-- Name: department_capacity_roles unique_dept_capacity_role; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_capacity_roles
    ADD CONSTRAINT unique_dept_capacity_role UNIQUE (department_id, capacity, role_key);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: activity_logs_action_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_action_idx ON public.activity_logs USING btree (action);


--
-- Name: activity_logs_created_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_created_at_idx ON public.activity_logs USING btree (created_at);


--
-- Name: activity_logs_loggable_created_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_loggable_created_index ON public.activity_logs USING btree (loggable_type, loggable_id, created_at);


--
-- Name: activity_logs_loggable_type_loggable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_loggable_type_loggable_id_index ON public.activity_logs USING btree (loggable_type, loggable_id);


--
-- Name: activity_logs_org_created_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_org_created_at_idx ON public.activity_logs USING btree (organization_id, created_at);


--
-- Name: activity_logs_org_loggable_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_org_loggable_idx ON public.activity_logs USING btree (organization_id, loggable_type, loggable_id);


--
-- Name: activity_logs_org_user_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_org_user_id_idx ON public.activity_logs USING btree (organization_id, user_id);


--
-- Name: activity_logs_organization_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_organization_id_idx ON public.activity_logs USING btree (organization_id);


--
-- Name: activity_logs_scope_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_scope_idx ON public.activity_logs USING btree (scope_type, scope_id);


--
-- Name: activity_logs_scope_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_logs_scope_type_idx ON public.activity_logs USING btree (scope_type);


--
-- Name: agenda_items_meeting_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agenda_items_meeting_idx ON public.meeting_agenda_items USING btree (meeting_id);


--
-- Name: agenda_items_meeting_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agenda_items_meeting_status_idx ON public.meeting_agenda_items USING btree (meeting_id, status);


--
-- Name: agenda_items_org_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX agenda_items_org_idx ON public.meeting_agenda_items USING btree (organization_id);


--
-- Name: archived_strategic_objectives_original_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX archived_strategic_objectives_original_id_index ON public.archived_strategic_objectives USING btree (original_id);


--
-- Name: archived_strategic_objectives_portfolio_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX archived_strategic_objectives_portfolio_id_index ON public.archived_strategic_objectives USING btree (portfolio_id);


--
-- Name: attachments_attachable_type_attachable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX attachments_attachable_type_attachable_id_index ON public.attachments USING btree (attachable_type, attachable_id);


--
-- Name: authorization_decision_audits_authorization_resource_id_action_; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_decision_audits_authorization_resource_id_action_ ON public.authorization_decision_audits USING btree (authorization_resource_id, action, created_at);


--
-- Name: authorization_decision_audits_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_decision_audits_user_id_created_at_index ON public.authorization_decision_audits USING btree (user_id, created_at);


--
-- Name: authorization_record_rules_priority_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_record_rules_priority_idx ON public.authorization_record_rules USING btree (authorization_resource_id, action, enabled, priority DESC) WHERE (enabled = true);


--
-- Name: authorization_record_rules_resource_action_enabled_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_record_rules_resource_action_enabled_idx ON public.authorization_record_rules USING btree (authorization_resource_id, action, enabled);


--
-- Name: authorization_record_rules_role_resource_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_record_rules_role_resource_idx ON public.authorization_record_rules USING btree (authorization_role_id, authorization_resource_id);


--
-- Name: authorization_record_rules_user_resource_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_record_rules_user_resource_idx ON public.authorization_record_rules USING btree (user_id, authorization_resource_id) WHERE (user_id IS NOT NULL);


--
-- Name: authorization_role_assignments_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_role_assignments_expires_at_index ON public.authorization_role_assignments USING btree (expires_at);


--
-- Name: authorization_role_assignments_organization_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_role_assignments_organization_id_index ON public.authorization_role_assignments USING btree (organization_id) WHERE (organization_id IS NOT NULL);


--
-- Name: authorization_role_assignments_scope_not_null_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX authorization_role_assignments_scope_not_null_unique ON public.authorization_role_assignments USING btree (authorization_role_id, user_id, scope_type, scope_id) WHERE (scope_id IS NOT NULL);


--
-- Name: authorization_role_assignments_scope_null_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX authorization_role_assignments_scope_null_unique ON public.authorization_role_assignments USING btree (authorization_role_id, user_id, scope_type) WHERE (scope_id IS NULL);


--
-- Name: authorization_role_assignments_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_role_assignments_user_id_index ON public.authorization_role_assignments USING btree (user_id);


--
-- Name: authorization_role_permissions_action_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_role_permissions_action_idx ON public.authorization_role_permissions USING btree (action);


--
-- Name: authorization_role_permissions_reach_module_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_role_permissions_reach_module_idx ON public.authorization_role_permissions USING gin (reach);


--
-- Name: authorization_role_permissions_resource_action_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX authorization_role_permissions_resource_action_idx ON public.authorization_role_permissions USING btree (authorization_resource_id, action);


--
-- Name: blockers_blockable_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blockers_blockable_status_index ON public.blockers USING btree (blockable_type, blockable_id, status, deleted_at);


--
-- Name: blockers_blockable_type_blockable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blockers_blockable_type_blockable_id_index ON public.blockers USING btree (blockable_type, blockable_id);


--
-- Name: blockers_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blockers_org_id_idx ON public.blockers USING btree (organization_id);


--
-- Name: blockers_status_severity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blockers_status_severity_index ON public.blockers USING btree (status, severity);


--
-- Name: comments_commentable_type_commentable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX comments_commentable_type_commentable_id_index ON public.comments USING btree (commentable_type, commentable_id);


--
-- Name: data_import_requests_response_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX data_import_requests_response_id_index ON public.data_import_requests USING btree (response_id);


--
-- Name: data_import_requests_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX data_import_requests_status_index ON public.data_import_requests USING btree (status);


--
-- Name: data_mapping_templates_survey_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX data_mapping_templates_survey_id_is_active_index ON public.data_mapping_templates USING btree (survey_id, is_active);


--
-- Name: department_capacity_roles_department_id_capacity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX department_capacity_roles_department_id_capacity_index ON public.department_capacity_roles USING btree (department_id, capacity);


--
-- Name: departments_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX departments_is_active_index ON public.departments USING btree (is_active);


--
-- Name: departments_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX departments_level_index ON public.departments USING btree (level);


--
-- Name: departments_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX departments_org_id_idx ON public.departments USING btree (organization_id);


--
-- Name: departments_parent_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX departments_parent_id_index ON public.departments USING btree (parent_id);


--
-- Name: departments_path_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX departments_path_index ON public.departments USING btree (path);


--
-- Name: email_otps_email_purpose_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX email_otps_email_purpose_index ON public.email_otps USING btree (email, purpose);


--
-- Name: employee_certificates_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_certificates_expires_at_index ON public.employee_certificates USING btree (expires_at);


--
-- Name: employee_certificates_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_certificates_type_index ON public.employee_certificates USING btree (type);


--
-- Name: employee_personal_info_iqama_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_personal_info_iqama_number_index ON public.employee_personal_info USING btree (iqama_number);


--
-- Name: employee_personal_info_national_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_personal_info_national_id_index ON public.employee_personal_info USING btree (national_id);


--
-- Name: employee_personal_info_nationality_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_personal_info_nationality_index ON public.employee_personal_info USING btree (nationality);


--
-- Name: employee_profiles_employee_no_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_profiles_employee_no_index ON public.employee_profiles USING btree (employee_no);


--
-- Name: employee_profiles_employment_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_profiles_employment_status_index ON public.employee_profiles USING btree (employment_status);


--
-- Name: employee_profiles_staff_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX employee_profiles_staff_category_index ON public.employee_profiles USING btree (staff_category);


--
-- Name: governance_rules_organization_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX governance_rules_organization_id_index ON public.governance_rules USING btree (organization_id);


--
-- Name: idx_tasks_project_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tasks_project_status ON public.tasks USING btree (project_id, status);


--
-- Name: initiatives_department_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX initiatives_department_id_status_index ON public.programs USING btree (department_id, status);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: kpi_links_created_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpi_links_created_by_index ON public.kpi_links USING btree (created_by);


--
-- Name: kpi_links_kpi_linkable_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpi_links_kpi_linkable_index ON public.kpi_links USING btree (kpi_id, linkable_type, linkable_id);


--
-- Name: kpi_links_linkable_type_linkable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpi_links_linkable_type_linkable_id_index ON public.kpi_links USING btree (linkable_type, linkable_id);


--
-- Name: kpi_links_org_linkable_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpi_links_org_linkable_index ON public.kpi_links USING btree (organization_id, linkable_type, linkable_id);


--
-- Name: kpi_measurements_kpi_id_measurement_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpi_measurements_kpi_id_measurement_date_index ON public.kpi_measurements USING btree (kpi_id, measurement_date);


--
-- Name: kpi_measurements_organization_id_measurement_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpi_measurements_organization_id_measurement_date_index ON public.kpi_measurements USING btree (organization_id, measurement_date);


--
-- Name: kpi_measurements_recorded_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpi_measurements_recorded_by_index ON public.kpi_measurements USING btree (recorded_by);


--
-- Name: kpi_measurements_source_type_source_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpi_measurements_source_type_source_id_index ON public.kpi_measurements USING btree (source_type, source_id);


--
-- Name: kpis_organization_id_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpis_organization_id_category_index ON public.kpis USING btree (organization_id, category);


--
-- Name: kpis_organization_id_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpis_organization_id_code_index ON public.kpis USING btree (organization_id, code);


--
-- Name: kpis_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpis_organization_id_status_index ON public.kpis USING btree (organization_id, status);


--
-- Name: kpis_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX kpis_owner_id_index ON public.kpis USING btree (owner_id);


--
-- Name: login_attempts_email_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX login_attempts_email_index ON public.login_attempts USING btree (email);


--
-- Name: login_attempts_email_successful_attempted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX login_attempts_email_successful_attempted_at_index ON public.login_attempts USING btree (email, successful, attempted_at);


--
-- Name: login_attempts_ip_address_attempted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX login_attempts_ip_address_attempted_at_index ON public.login_attempts USING btree (ip_address, attempted_at);


--
-- Name: login_attempts_ip_address_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX login_attempts_ip_address_index ON public.login_attempts USING btree (ip_address);


--
-- Name: meeting_attendees_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meeting_attendees_user_idx ON public.meeting_attendees USING btree (user_id);


--
-- Name: meeting_categories_org_active_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meeting_categories_org_active_idx ON public.meeting_categories USING btree (organization_id, is_active);


--
-- Name: meeting_resolutions_due_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meeting_resolutions_due_date_idx ON public.meeting_resolutions USING btree (due_date);


--
-- Name: meeting_resolutions_meeting_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meeting_resolutions_meeting_status_idx ON public.meeting_resolutions USING btree (meeting_id, status);


--
-- Name: meeting_resolutions_org_kind_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meeting_resolutions_org_kind_idx ON public.meeting_resolutions USING btree (organization_id, kind);


--
-- Name: meeting_resolutions_org_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meeting_resolutions_org_status_idx ON public.meeting_resolutions USING btree (organization_id, status);


--
-- Name: meeting_resolutions_owner_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meeting_resolutions_owner_status_idx ON public.meeting_resolutions USING btree (owner_id, status);


--
-- Name: meetings_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meetings_org_id_idx ON public.meetings USING btree (organization_id);


--
-- Name: meetings_org_scheduled_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meetings_org_scheduled_at_idx ON public.meetings USING btree (organization_id, scheduled_at);


--
-- Name: meetings_org_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meetings_org_status_idx ON public.meetings USING btree (organization_id, status);


--
-- Name: meetings_reference_number_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX meetings_reference_number_unique ON public.meetings USING btree (reference_number) WHERE (reference_number IS NOT NULL);


--
-- Name: meetings_reminder_sent_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meetings_reminder_sent_at_idx ON public.meetings USING btree (reminder_sent_at);


--
-- Name: meetings_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX meetings_subject_type_subject_id_index ON public.meetings USING btree (subject_type, subject_id);


--
-- Name: milestones_due_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX milestones_due_date_index ON public.milestones USING btree (due_date);


--
-- Name: milestones_project_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX milestones_project_id_index ON public.milestones USING btree (project_id);


--
-- Name: milestones_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX milestones_status_index ON public.milestones USING btree (status);


--
-- Name: notifications_notifiable_type_notifiable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_notifiable_type_notifiable_id_index ON public.notifications USING btree (notifiable_type, notifiable_id);


--
-- Name: organizations_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_is_active_index ON public.organizations USING btree (is_active);


--
-- Name: organizations_parent_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_parent_id_idx ON public.organizations USING btree (parent_id);


--
-- Name: organizations_parent_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_parent_type_idx ON public.organizations USING btree (parent_id, type);


--
-- Name: organizations_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX organizations_type_idx ON public.organizations USING btree (type);


--
-- Name: ovr_incident_participants_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_incident_participants_user_id_index ON public.ovr_incident_participants USING btree (user_id);


--
-- Name: ovr_incident_reports_contributing_factors_gin; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_incident_reports_contributing_factors_gin ON public.ovr_incident_reports USING gin (((contributing_factors)::jsonb) jsonb_path_ops) WHERE (contributing_factors IS NOT NULL);


--
-- Name: ovr_incident_reports_organization_id_severity_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_incident_reports_organization_id_severity_level_index ON public.ovr_incident_reports USING btree (organization_id, severity_level);


--
-- Name: ovr_incident_reports_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_incident_reports_organization_id_status_index ON public.ovr_incident_reports USING btree (organization_id, status);


--
-- Name: ovr_incident_reports_report_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_incident_reports_report_number_index ON public.ovr_incident_reports USING btree (report_number);


--
-- Name: ovr_incident_reports_reporter_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_incident_reports_reporter_id_status_index ON public.ovr_incident_reports USING btree (reporter_id, status);


--
-- Name: ovr_incident_types_organization_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_incident_types_organization_id_is_active_index ON public.ovr_incident_types USING btree (organization_id, is_active);


--
-- Name: ovr_report_comments_report_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_report_comments_report_id_index ON public.ovr_report_comments USING btree (report_id);


--
-- Name: ovr_reportable_types_organization_id_incident_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_reportable_types_organization_id_incident_type_id_index ON public.ovr_reportable_types USING btree (organization_id, incident_type_id);


--
-- Name: ovr_status_history_report_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ovr_status_history_report_id_index ON public.ovr_status_history USING btree (report_id);


--
-- Name: permission_audits_actor_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audits_actor_id_index ON public.authorization_assignment_audits USING btree (actor_id);


--
-- Name: permission_audits_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audits_created_at_index ON public.authorization_assignment_audits USING btree (created_at);


--
-- Name: permission_audits_event_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audits_event_index ON public.authorization_assignment_audits USING btree (event);


--
-- Name: permission_audits_scope_type_scope_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audits_scope_type_scope_id_index ON public.authorization_assignment_audits USING btree (scope_type, scope_id);


--
-- Name: permission_audits_target_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audits_target_user_id_index ON public.authorization_assignment_audits USING btree (target_user_id);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: portfolios_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX portfolios_org_id_idx ON public.portfolios USING btree (organization_id);


--
-- Name: portfolios_portfolio_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX portfolios_portfolio_status_index ON public.portfolios USING btree (portfolio_status);


--
-- Name: portfolios_priority_rank_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX portfolios_priority_rank_index ON public.portfolios USING btree (priority_rank);


--
-- Name: programs_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX programs_org_id_idx ON public.programs USING btree (organization_id);


--
-- Name: programs_portfolio_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX programs_portfolio_id_index ON public.programs USING btree (portfolio_id);


--
-- Name: programs_portfolio_status_deleted_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX programs_portfolio_status_deleted_index ON public.programs USING btree (portfolio_id, status, deleted_at);


--
-- Name: programs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX programs_status_index ON public.programs USING btree (status);


--
-- Name: project_expenses_project_id_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX project_expenses_project_id_category_index ON public.project_expenses USING btree (project_id, category);


--
-- Name: project_expenses_project_id_expense_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX project_expenses_project_id_expense_date_index ON public.project_expenses USING btree (project_id, expense_date);


--
-- Name: project_risks_project_id_deleted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX project_risks_project_id_deleted_at_index ON public.project_risks USING btree (project_id, deleted_at);


--
-- Name: project_risks_project_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX project_risks_project_id_status_index ON public.project_risks USING btree (project_id, status);


--
-- Name: project_risks_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX project_risks_status_index ON public.project_risks USING btree (status);


--
-- Name: projects_department_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_department_id_index ON public.projects USING btree (department_id);


--
-- Name: projects_end_date_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_end_date_status_index ON public.projects USING btree (end_date, status);


--
-- Name: projects_org_dept_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_org_dept_status_index ON public.projects USING btree (organization_id, department_id, status);


--
-- Name: projects_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_org_id_idx ON public.projects USING btree (organization_id);


--
-- Name: projects_org_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_org_type_index ON public.projects USING btree (organization_id, type);


--
-- Name: projects_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_priority_index ON public.projects USING btree (priority);


--
-- Name: projects_program_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_program_id_status_index ON public.projects USING btree (program_id, status);


--
-- Name: projects_status_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_status_created_at_index ON public.projects USING btree (status, created_at);


--
-- Name: projects_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_status_index ON public.projects USING btree (status);


--
-- Name: projects_status_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX projects_status_priority_index ON public.projects USING btree (status, priority);


--
-- Name: recommendations_due_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX recommendations_due_date_idx ON public.recommendations USING btree (due_date);


--
-- Name: recommendations_kind_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX recommendations_kind_status_idx ON public.recommendations USING btree (kind, status);


--
-- Name: recommendations_meeting_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX recommendations_meeting_id_idx ON public.recommendations USING btree (meeting_id);


--
-- Name: recommendations_org_assignee_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX recommendations_org_assignee_idx ON public.recommendations USING btree (organization_id, assignee_id);


--
-- Name: recommendations_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX recommendations_org_id_idx ON public.recommendations USING btree (organization_id);


--
-- Name: recommendations_org_priority_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX recommendations_org_priority_idx ON public.recommendations USING btree (organization_id, priority);


--
-- Name: recommendations_org_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX recommendations_org_status_idx ON public.recommendations USING btree (organization_id, status);


--
-- Name: recommendations_reference_number_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX recommendations_reference_number_unique ON public.recommendations USING btree (reference_number) WHERE (reference_number IS NOT NULL);


--
-- Name: resolution_links_linkable_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX resolution_links_linkable_idx ON public.resolution_links USING btree (linkable_type, linkable_id);


--
-- Name: resolution_links_resolution_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX resolution_links_resolution_idx ON public.resolution_links USING btree (resolution_id);


--
-- Name: reviews_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX reviews_org_id_idx ON public.reviews USING btree (organization_id);


--
-- Name: reviews_review_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX reviews_review_date_index ON public.reviews USING btree (review_date);


--
-- Name: reviews_reviewable_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX reviews_reviewable_index ON public.reviews USING btree (reviewable_type, reviewable_id, deleted_at);


--
-- Name: reviews_reviewable_type_reviewable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX reviews_reviewable_type_reviewable_id_index ON public.reviews USING btree (reviewable_type, reviewable_id);


--
-- Name: reviews_type_pdca_phase_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX reviews_type_pdca_phase_index ON public.reviews USING btree (type, pdca_phase);


--
-- Name: risk_action_updates_action_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_action_updates_action_created_idx ON public.risk_action_updates USING btree (risk_action_id, created_at);


--
-- Name: risk_action_updates_org_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_action_updates_org_created_idx ON public.risk_action_updates USING btree (organization_id, created_at);


--
-- Name: risk_actions_org_due_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_actions_org_due_date_idx ON public.risk_actions USING btree (organization_id, due_date);


--
-- Name: risk_actions_org_owner_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_actions_org_owner_idx ON public.risk_actions USING btree (organization_id, owner_id);


--
-- Name: risk_actions_org_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_actions_org_status_idx ON public.risk_actions USING btree (organization_id, status);


--
-- Name: risk_actions_risk_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_actions_risk_status_idx ON public.risk_actions USING btree (risk_id, status);


--
-- Name: risk_alerts_org_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_alerts_org_type_idx ON public.risk_alerts USING btree (organization_id, type);


--
-- Name: risk_alerts_risk_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_alerts_risk_type_idx ON public.risk_alerts USING btree (risk_id, type);


--
-- Name: risk_alerts_sent_to_read_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_alerts_sent_to_read_idx ON public.risk_alerts USING btree (sent_to, read_at);


--
-- Name: risk_assessments_org_level_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_assessments_org_level_idx ON public.risk_assessments USING btree (organization_id, level);


--
-- Name: risk_assessments_org_next_review_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_assessments_org_next_review_idx ON public.risk_assessments USING btree (organization_id, next_review_at);


--
-- Name: risk_assessments_risk_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_assessments_risk_created_idx ON public.risk_assessments USING btree (risk_id, created_at);


--
-- Name: risk_status_changes_org_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_status_changes_org_status_idx ON public.risk_status_changes USING btree (organization_id, to_status);


--
-- Name: risk_status_changes_risk_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risk_status_changes_risk_created_idx ON public.risk_status_changes USING btree (risk_id, created_at);


--
-- Name: risks_matrix_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_matrix_idx ON public.risks USING btree (current_likelihood, current_impact);


--
-- Name: risks_org_department_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_org_department_idx ON public.risks USING btree (organization_id, department_id);


--
-- Name: risks_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_org_id_idx ON public.risks USING btree (organization_id);


--
-- Name: risks_org_level_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_org_level_idx ON public.risks USING btree (organization_id, current_level);


--
-- Name: risks_org_owner_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_org_owner_idx ON public.risks USING btree (organization_id, owner_id);


--
-- Name: risks_org_score_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_org_score_idx ON public.risks USING btree (organization_id, current_score);


--
-- Name: risks_org_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_org_status_idx ON public.risks USING btree (organization_id, status);


--
-- Name: risks_org_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_org_status_index ON public.risks USING btree (organization_id, status);


--
-- Name: risks_riskable_type_riskable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_riskable_type_riskable_id_index ON public.risks USING btree (riskable_type, riskable_id);


--
-- Name: risks_target_close_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX risks_target_close_date_idx ON public.risks USING btree (target_close_date);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: stakeholders_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX stakeholders_user_id_index ON public.stakeholders USING btree (user_id);


--
-- Name: strategic_directions_status_deleted_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX strategic_directions_status_deleted_at_index ON public.portfolios USING btree (status, deleted_at);


--
-- Name: survey_answer_files_answer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX survey_answer_files_answer_id_index ON public.survey_answer_files USING btree (answer_id);


--
-- Name: survey_field_answers_field_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX survey_field_answers_field_key_index ON public.survey_field_answers USING btree (field_key);


--
-- Name: survey_fields_survey_id_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX survey_fields_survey_id_order_index ON public.survey_fields USING btree (survey_id, "order");


--
-- Name: survey_invitations_survey_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX survey_invitations_survey_id_status_index ON public.survey_invitations USING btree (survey_id, status);


--
-- Name: survey_responses_fingerprint_hash_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX survey_responses_fingerprint_hash_created_at_index ON public.survey_responses USING btree (fingerprint_hash, created_at);


--
-- Name: survey_responses_survey_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX survey_responses_survey_id_created_at_index ON public.survey_responses USING btree (survey_id, created_at);


--
-- Name: survey_responses_survey_org_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX survey_responses_survey_org_idx ON public.survey_responses USING btree (survey_id, respondent_organization_id);


--
-- Name: survey_sections_survey_id_order_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX survey_sections_survey_id_order_index ON public.survey_sections USING btree (survey_id, "order");


--
-- Name: survey_versions_survey_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX survey_versions_survey_id_index ON public.survey_versions USING btree (survey_id);


--
-- Name: surveys_canonical_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX surveys_canonical_id_index ON public.surveys USING btree (canonical_id);


--
-- Name: surveys_organization_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX surveys_organization_id_status_index ON public.surveys USING btree (organization_id, status);


--
-- Name: tasks_assigned_to_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_assigned_to_index ON public.tasks USING btree (assigned_to);


--
-- Name: tasks_assigned_to_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_assigned_to_status_index ON public.tasks USING btree (assigned_to, status);


--
-- Name: tasks_due_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_due_date_index ON public.tasks USING btree (due_date);


--
-- Name: tasks_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_org_id_idx ON public.tasks USING btree (organization_id);


--
-- Name: tasks_org_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_org_status_index ON public.tasks USING btree (organization_id, status);


--
-- Name: tasks_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_owner_id_index ON public.tasks USING btree (owner_id);


--
-- Name: tasks_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_priority_index ON public.tasks USING btree (priority);


--
-- Name: tasks_project_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_project_id_index ON public.tasks USING btree (project_id);


--
-- Name: tasks_project_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_project_id_status_index ON public.tasks USING btree (project_id, status);


--
-- Name: tasks_source_type_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_source_type_id_index ON public.tasks USING btree (source_type, source_id);


--
-- Name: tasks_source_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_source_type_index ON public.tasks USING btree (source_type);


--
-- Name: tasks_status_due_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_status_due_date_index ON public.tasks USING btree (status, due_date);


--
-- Name: tasks_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_status_index ON public.tasks USING btree (status);


--
-- Name: tasks_status_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_status_priority_index ON public.tasks USING btree (status, priority);


--
-- Name: tasks_type_department_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_type_department_id_index ON public.tasks USING btree (type, department_id);


--
-- Name: tasks_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_type_index ON public.tasks USING btree (type);


--
-- Name: tasks_type_owner_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tasks_type_owner_id_index ON public.tasks USING btree (type, owner_id);


--
-- Name: users_department_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_department_id_index ON public.users USING btree (department_id);


--
-- Name: users_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_is_active_index ON public.users USING btree (is_active);


--
-- Name: users_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_name_index ON public.users USING btree (name);


--
-- Name: users_org_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_org_id_idx ON public.users USING btree (organization_id);


--
-- Name: users_registration_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_registration_status_index ON public.users USING btree (registration_status);


--
-- Name: activity_logs activity_logs_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs
    ADD CONSTRAINT activity_logs_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: activity_logs activity_logs_target_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs
    ADD CONSTRAINT activity_logs_target_user_id_foreign FOREIGN KEY (target_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: activity_logs activity_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_logs
    ADD CONSTRAINT activity_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: attachments attachments_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attachments
    ADD CONSTRAINT attachments_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: authorization_decision_audits authorization_decision_audits_authorization_resource_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_decision_audits
    ADD CONSTRAINT authorization_decision_audits_authorization_resource_id_foreign FOREIGN KEY (authorization_resource_id) REFERENCES public.authorization_resources(id) ON DELETE CASCADE;


--
-- Name: authorization_decision_audits authorization_decision_audits_matched_authorization_record_rule; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_decision_audits
    ADD CONSTRAINT authorization_decision_audits_matched_authorization_record_rule FOREIGN KEY (matched_authorization_record_rule_id) REFERENCES public.authorization_record_rules(id) ON DELETE SET NULL;


--
-- Name: authorization_decision_audits authorization_decision_audits_matched_authorization_role_assign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_decision_audits
    ADD CONSTRAINT authorization_decision_audits_matched_authorization_role_assign FOREIGN KEY (matched_authorization_role_assignment_id) REFERENCES public.authorization_role_assignments(id) ON DELETE SET NULL;


--
-- Name: authorization_decision_audits authorization_decision_audits_matched_authorization_role_id_for; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_decision_audits
    ADD CONSTRAINT authorization_decision_audits_matched_authorization_role_id_for FOREIGN KEY (matched_authorization_role_id) REFERENCES public.authorization_roles(id) ON DELETE SET NULL;


--
-- Name: authorization_decision_audits authorization_decision_audits_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_decision_audits
    ADD CONSTRAINT authorization_decision_audits_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: authorization_record_rules authorization_record_rules_authorization_resource_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_record_rules
    ADD CONSTRAINT authorization_record_rules_authorization_resource_id_foreign FOREIGN KEY (authorization_resource_id) REFERENCES public.authorization_resources(id) ON DELETE CASCADE;


--
-- Name: authorization_record_rules authorization_record_rules_authorization_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_record_rules
    ADD CONSTRAINT authorization_record_rules_authorization_role_id_foreign FOREIGN KEY (authorization_role_id) REFERENCES public.authorization_roles(id) ON DELETE SET NULL;


--
-- Name: authorization_record_rules authorization_record_rules_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_record_rules
    ADD CONSTRAINT authorization_record_rules_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: authorization_role_assignments authorization_role_assignments_authorization_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_role_assignments
    ADD CONSTRAINT authorization_role_assignments_authorization_role_id_foreign FOREIGN KEY (authorization_role_id) REFERENCES public.authorization_roles(id) ON DELETE CASCADE;


--
-- Name: authorization_role_assignments authorization_role_assignments_granted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_role_assignments
    ADD CONSTRAINT authorization_role_assignments_granted_by_foreign FOREIGN KEY (granted_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: authorization_role_assignments authorization_role_assignments_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_role_assignments
    ADD CONSTRAINT authorization_role_assignments_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: authorization_role_assignments authorization_role_assignments_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_role_assignments
    ADD CONSTRAINT authorization_role_assignments_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: authorization_role_permissions authorization_role_permissions_authorization_resource_id_foreig; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_role_permissions
    ADD CONSTRAINT authorization_role_permissions_authorization_resource_id_foreig FOREIGN KEY (authorization_resource_id) REFERENCES public.authorization_resources(id) ON DELETE CASCADE;


--
-- Name: authorization_role_permissions authorization_role_permissions_authorization_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_role_permissions
    ADD CONSTRAINT authorization_role_permissions_authorization_role_id_foreign FOREIGN KEY (authorization_role_id) REFERENCES public.authorization_roles(id) ON DELETE CASCADE;


--
-- Name: blockers blockers_assigned_to_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blockers
    ADD CONSTRAINT blockers_assigned_to_foreign FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: blockers blockers_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blockers
    ADD CONSTRAINT blockers_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: blockers blockers_reported_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blockers
    ADD CONSTRAINT blockers_reported_by_foreign FOREIGN KEY (reported_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: comments comments_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comments
    ADD CONSTRAINT comments_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: data_import_requests data_import_requests_response_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_import_requests
    ADD CONSTRAINT data_import_requests_response_id_foreign FOREIGN KEY (response_id) REFERENCES public.survey_responses(id) ON DELETE CASCADE;


--
-- Name: data_import_requests data_import_requests_reviewed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_import_requests
    ADD CONSTRAINT data_import_requests_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: data_import_requests data_import_requests_template_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_import_requests
    ADD CONSTRAINT data_import_requests_template_id_foreign FOREIGN KEY (template_id) REFERENCES public.data_mapping_templates(id) ON DELETE SET NULL;


--
-- Name: data_mapping_templates data_mapping_templates_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_mapping_templates
    ADD CONSTRAINT data_mapping_templates_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: data_mapping_templates data_mapping_templates_survey_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.data_mapping_templates
    ADD CONSTRAINT data_mapping_templates_survey_id_foreign FOREIGN KEY (survey_id) REFERENCES public.surveys(id) ON DELETE CASCADE;


--
-- Name: department_capacity_roles department_capacity_roles_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.department_capacity_roles
    ADD CONSTRAINT department_capacity_roles_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE CASCADE;


--
-- Name: departments departments_manager_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_manager_id_foreign FOREIGN KEY (manager_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: departments departments_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: departments departments_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: employee_certificates employee_certificates_employee_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_certificates
    ADD CONSTRAINT employee_certificates_employee_profile_id_foreign FOREIGN KEY (employee_profile_id) REFERENCES public.employee_profiles(id) ON DELETE CASCADE;


--
-- Name: employee_personal_info employee_personal_info_employee_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_personal_info
    ADD CONSTRAINT employee_personal_info_employee_profile_id_foreign FOREIGN KEY (employee_profile_id) REFERENCES public.employee_profiles(id) ON DELETE CASCADE;


--
-- Name: employee_profiles employee_profiles_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.employee_profiles
    ADD CONSTRAINT employee_profiles_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: governance_rules governance_rules_governing_unit_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.governance_rules
    ADD CONSTRAINT governance_rules_governing_unit_id_foreign FOREIGN KEY (governing_unit_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: governance_rules governance_rules_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.governance_rules
    ADD CONSTRAINT governance_rules_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: programs initiatives_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs
    ADD CONSTRAINT initiatives_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: programs initiatives_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs
    ADD CONSTRAINT initiatives_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: programs initiatives_portfolio_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs
    ADD CONSTRAINT initiatives_portfolio_id_foreign FOREIGN KEY (portfolio_id) REFERENCES public.portfolios(id) ON DELETE SET NULL;


--
-- Name: kpi_links kpi_links_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_links
    ADD CONSTRAINT kpi_links_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: kpi_links kpi_links_kpi_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_links
    ADD CONSTRAINT kpi_links_kpi_id_foreign FOREIGN KEY (kpi_id) REFERENCES public.kpis(id) ON DELETE CASCADE;


--
-- Name: kpi_links kpi_links_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_links
    ADD CONSTRAINT kpi_links_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: kpi_measurements kpi_measurements_kpi_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_measurements
    ADD CONSTRAINT kpi_measurements_kpi_id_foreign FOREIGN KEY (kpi_id) REFERENCES public.kpis(id) ON DELETE CASCADE;


--
-- Name: kpi_measurements kpi_measurements_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_measurements
    ADD CONSTRAINT kpi_measurements_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: kpi_measurements kpi_measurements_recorded_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpi_measurements
    ADD CONSTRAINT kpi_measurements_recorded_by_foreign FOREIGN KEY (recorded_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: kpis kpis_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpis
    ADD CONSTRAINT kpis_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: kpis kpis_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpis
    ADD CONSTRAINT kpis_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: kpis kpis_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpis
    ADD CONSTRAINT kpis_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: kpis kpis_owner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kpis
    ADD CONSTRAINT kpis_owner_id_foreign FOREIGN KEY (owner_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: meeting_agenda_items meeting_agenda_items_meeting_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_agenda_items
    ADD CONSTRAINT meeting_agenda_items_meeting_id_foreign FOREIGN KEY (meeting_id) REFERENCES public.meetings(id) ON DELETE CASCADE;


--
-- Name: meeting_agenda_items meeting_agenda_items_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_agenda_items
    ADD CONSTRAINT meeting_agenda_items_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: meeting_agenda_items meeting_agenda_items_proposed_by_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_agenda_items
    ADD CONSTRAINT meeting_agenda_items_proposed_by_id_foreign FOREIGN KEY (proposed_by_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: meeting_attendees meeting_attendees_meeting_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_attendees
    ADD CONSTRAINT meeting_attendees_meeting_id_foreign FOREIGN KEY (meeting_id) REFERENCES public.meetings(id) ON DELETE CASCADE;


--
-- Name: meeting_attendees meeting_attendees_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_attendees
    ADD CONSTRAINT meeting_attendees_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: meeting_categories meeting_categories_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_categories
    ADD CONSTRAINT meeting_categories_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: meeting_resolutions meeting_resolutions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_resolutions
    ADD CONSTRAINT meeting_resolutions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: meeting_resolutions meeting_resolutions_hold_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_resolutions
    ADD CONSTRAINT meeting_resolutions_hold_by_foreign FOREIGN KEY (hold_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: meeting_resolutions meeting_resolutions_meeting_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_resolutions
    ADD CONSTRAINT meeting_resolutions_meeting_id_foreign FOREIGN KEY (meeting_id) REFERENCES public.meetings(id) ON DELETE RESTRICT;


--
-- Name: meeting_resolutions meeting_resolutions_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_resolutions
    ADD CONSTRAINT meeting_resolutions_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: meeting_resolutions meeting_resolutions_owner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_resolutions
    ADD CONSTRAINT meeting_resolutions_owner_id_foreign FOREIGN KEY (owner_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: meeting_settings meeting_settings_default_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_settings
    ADD CONSTRAINT meeting_settings_default_category_id_foreign FOREIGN KEY (default_category_id) REFERENCES public.meeting_categories(id) ON DELETE SET NULL;


--
-- Name: meeting_settings meeting_settings_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meeting_settings
    ADD CONSTRAINT meeting_settings_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: meetings meetings_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meetings
    ADD CONSTRAINT meetings_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.meeting_categories(id) ON DELETE SET NULL;


--
-- Name: meetings meetings_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meetings
    ADD CONSTRAINT meetings_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: meetings meetings_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meetings
    ADD CONSTRAINT meetings_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: meetings meetings_organizer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.meetings
    ADD CONSTRAINT meetings_organizer_id_foreign FOREIGN KEY (organizer_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: milestone_deliverables milestone_deliverables_milestone_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.milestone_deliverables
    ADD CONSTRAINT milestone_deliverables_milestone_id_foreign FOREIGN KEY (milestone_id) REFERENCES public.milestones(id) ON DELETE CASCADE;


--
-- Name: milestones milestones_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.milestones
    ADD CONSTRAINT milestones_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: organizations organizations_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: organizations organizations_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.organizations(id) ON DELETE RESTRICT;


--
-- Name: ovr_incident_participants ovr_incident_participants_incident_report_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_participants
    ADD CONSTRAINT ovr_incident_participants_incident_report_id_foreign FOREIGN KEY (incident_report_id) REFERENCES public.ovr_incident_reports(id) ON DELETE CASCADE;


--
-- Name: ovr_incident_participants ovr_incident_participants_invited_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_participants
    ADD CONSTRAINT ovr_incident_participants_invited_by_foreign FOREIGN KEY (invited_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ovr_incident_participants ovr_incident_participants_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_participants
    ADD CONSTRAINT ovr_incident_participants_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: ovr_incident_reports ovr_incident_reports_assigned_to_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_assigned_to_foreign FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ovr_incident_reports ovr_incident_reports_closed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_closed_by_foreign FOREIGN KEY (closed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ovr_incident_reports ovr_incident_reports_incident_type_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_incident_type_id_foreign FOREIGN KEY (incident_type_id) REFERENCES public.ovr_incident_types(id);


--
-- Name: ovr_incident_reports ovr_incident_reports_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE CASCADE;


--
-- Name: ovr_incident_reports ovr_incident_reports_reopened_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_reopened_by_foreign FOREIGN KEY (reopened_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: ovr_incident_reports ovr_incident_reports_reportable_incident_type_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_reportable_incident_type_id_foreign FOREIGN KEY (reportable_incident_type_id) REFERENCES public.ovr_reportable_types(id) ON DELETE SET NULL;


--
-- Name: ovr_incident_reports ovr_incident_reports_reporter_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_reporter_department_id_foreign FOREIGN KEY (reporter_department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: ovr_incident_reports ovr_incident_reports_reporter_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_reporter_id_foreign FOREIGN KEY (reporter_id) REFERENCES public.users(id);


--
-- Name: ovr_incident_reports ovr_incident_reports_reporter_section_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_reports
    ADD CONSTRAINT ovr_incident_reports_reporter_section_id_foreign FOREIGN KEY (reporter_section_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: ovr_incident_types ovr_incident_types_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_incident_types
    ADD CONSTRAINT ovr_incident_types_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: ovr_report_comments ovr_report_comments_report_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_report_comments
    ADD CONSTRAINT ovr_report_comments_report_id_foreign FOREIGN KEY (report_id) REFERENCES public.ovr_incident_reports(id) ON DELETE CASCADE;


--
-- Name: ovr_report_comments ovr_report_comments_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_report_comments
    ADD CONSTRAINT ovr_report_comments_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: ovr_reportable_types ovr_reportable_types_incident_type_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_reportable_types
    ADD CONSTRAINT ovr_reportable_types_incident_type_id_foreign FOREIGN KEY (incident_type_id) REFERENCES public.ovr_incident_types(id) ON DELETE CASCADE;


--
-- Name: ovr_reportable_types ovr_reportable_types_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_reportable_types
    ADD CONSTRAINT ovr_reportable_types_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: ovr_status_history ovr_status_history_changed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_status_history
    ADD CONSTRAINT ovr_status_history_changed_by_foreign FOREIGN KEY (changed_by) REFERENCES public.users(id);


--
-- Name: ovr_status_history ovr_status_history_report_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ovr_status_history
    ADD CONSTRAINT ovr_status_history_report_id_foreign FOREIGN KEY (report_id) REFERENCES public.ovr_incident_reports(id) ON DELETE CASCADE;


--
-- Name: authorization_assignment_audits permission_audits_actor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_assignment_audits
    ADD CONSTRAINT permission_audits_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: authorization_assignment_audits permission_audits_target_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.authorization_assignment_audits
    ADD CONSTRAINT permission_audits_target_user_id_foreign FOREIGN KEY (target_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: portfolios portfolios_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.portfolios
    ADD CONSTRAINT portfolios_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: programs programs_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.programs
    ADD CONSTRAINT programs_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: project_expenses project_expenses_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_expenses
    ADD CONSTRAINT project_expenses_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: project_expenses project_expenses_finalized_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_expenses
    ADD CONSTRAINT project_expenses_finalized_by_foreign FOREIGN KEY (finalized_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: project_expenses project_expenses_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_expenses
    ADD CONSTRAINT project_expenses_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: project_expenses project_expenses_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_expenses
    ADD CONSTRAINT project_expenses_task_id_foreign FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE SET NULL;


--
-- Name: project_risks project_risks_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.project_risks
    ADD CONSTRAINT project_risks_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: projects projects_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: projects projects_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: projects projects_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: projects projects_program_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_program_id_foreign FOREIGN KEY (program_id) REFERENCES public.programs(id) ON DELETE SET NULL;


--
-- Name: recommendations recommendations_assignee_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendations
    ADD CONSTRAINT recommendations_assignee_id_foreign FOREIGN KEY (assignee_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: recommendations recommendations_deferred_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendations
    ADD CONSTRAINT recommendations_deferred_by_foreign FOREIGN KEY (deferred_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: recommendations recommendations_made_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendations
    ADD CONSTRAINT recommendations_made_by_foreign FOREIGN KEY (made_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: recommendations recommendations_meeting_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendations
    ADD CONSTRAINT recommendations_meeting_id_foreign FOREIGN KEY (meeting_id) REFERENCES public.meetings(id) ON DELETE RESTRICT;


--
-- Name: recommendations recommendations_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendations
    ADD CONSTRAINT recommendations_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: recommendations recommendations_requested_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recommendations
    ADD CONSTRAINT recommendations_requested_by_foreign FOREIGN KEY (requested_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: resolution_links resolution_links_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resolution_links
    ADD CONSTRAINT resolution_links_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: resolution_links resolution_links_resolution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resolution_links
    ADD CONSTRAINT resolution_links_resolution_id_foreign FOREIGN KEY (resolution_id) REFERENCES public.meeting_resolutions(id) ON DELETE CASCADE;


--
-- Name: reviews reviews_conducted_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_conducted_by_foreign FOREIGN KEY (conducted_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: reviews reviews_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: risk_action_updates risk_action_updates_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_action_updates
    ADD CONSTRAINT risk_action_updates_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: risk_action_updates risk_action_updates_risk_action_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_action_updates
    ADD CONSTRAINT risk_action_updates_risk_action_id_foreign FOREIGN KEY (risk_action_id) REFERENCES public.risk_actions(id) ON DELETE CASCADE;


--
-- Name: risk_action_updates risk_action_updates_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_action_updates
    ADD CONSTRAINT risk_action_updates_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: risk_actions risk_actions_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_actions
    ADD CONSTRAINT risk_actions_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: risk_actions risk_actions_owner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_actions
    ADD CONSTRAINT risk_actions_owner_id_foreign FOREIGN KEY (owner_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: risk_actions risk_actions_risk_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_actions
    ADD CONSTRAINT risk_actions_risk_id_foreign FOREIGN KEY (risk_id) REFERENCES public.risks(id) ON DELETE CASCADE;


--
-- Name: risk_alerts risk_alerts_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_alerts
    ADD CONSTRAINT risk_alerts_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: risk_alerts risk_alerts_risk_action_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_alerts
    ADD CONSTRAINT risk_alerts_risk_action_id_foreign FOREIGN KEY (risk_action_id) REFERENCES public.risk_actions(id) ON DELETE CASCADE;


--
-- Name: risk_alerts risk_alerts_risk_assessment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_alerts
    ADD CONSTRAINT risk_alerts_risk_assessment_id_foreign FOREIGN KEY (risk_assessment_id) REFERENCES public.risk_assessments(id) ON DELETE CASCADE;


--
-- Name: risk_alerts risk_alerts_risk_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_alerts
    ADD CONSTRAINT risk_alerts_risk_id_foreign FOREIGN KEY (risk_id) REFERENCES public.risks(id) ON DELETE CASCADE;


--
-- Name: risk_alerts risk_alerts_sent_to_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_alerts
    ADD CONSTRAINT risk_alerts_sent_to_foreign FOREIGN KEY (sent_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: risk_assessments risk_assessments_assessor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_assessments
    ADD CONSTRAINT risk_assessments_assessor_id_foreign FOREIGN KEY (assessor_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: risk_assessments risk_assessments_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_assessments
    ADD CONSTRAINT risk_assessments_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: risk_assessments risk_assessments_risk_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_assessments
    ADD CONSTRAINT risk_assessments_risk_id_foreign FOREIGN KEY (risk_id) REFERENCES public.risks(id) ON DELETE CASCADE;


--
-- Name: risk_status_changes risk_status_changes_changed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_status_changes
    ADD CONSTRAINT risk_status_changes_changed_by_foreign FOREIGN KEY (changed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: risk_status_changes risk_status_changes_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_status_changes
    ADD CONSTRAINT risk_status_changes_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: risk_status_changes risk_status_changes_risk_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risk_status_changes
    ADD CONSTRAINT risk_status_changes_risk_id_foreign FOREIGN KEY (risk_id) REFERENCES public.risks(id) ON DELETE CASCADE;


--
-- Name: risks risks_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risks
    ADD CONSTRAINT risks_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: risks risks_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risks
    ADD CONSTRAINT risks_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: risks risks_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risks
    ADD CONSTRAINT risks_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: risks risks_owner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.risks
    ADD CONSTRAINT risks_owner_id_foreign FOREIGN KEY (owner_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: stakeholders stakeholders_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stakeholders
    ADD CONSTRAINT stakeholders_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: stakeholders stakeholders_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stakeholders
    ADD CONSTRAINT stakeholders_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: portfolios strategic_directions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.portfolios
    ADD CONSTRAINT strategic_directions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: survey_answer_files survey_answer_files_answer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_answer_files
    ADD CONSTRAINT survey_answer_files_answer_id_foreign FOREIGN KEY (answer_id) REFERENCES public.survey_field_answers(id) ON DELETE CASCADE;


--
-- Name: survey_field_answers survey_field_answers_field_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_field_answers
    ADD CONSTRAINT survey_field_answers_field_id_foreign FOREIGN KEY (field_id) REFERENCES public.survey_fields(id) ON DELETE CASCADE;


--
-- Name: survey_field_answers survey_field_answers_response_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_field_answers
    ADD CONSTRAINT survey_field_answers_response_id_foreign FOREIGN KEY (response_id) REFERENCES public.survey_responses(id) ON DELETE CASCADE;


--
-- Name: survey_fields survey_fields_section_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_fields
    ADD CONSTRAINT survey_fields_section_id_foreign FOREIGN KEY (section_id) REFERENCES public.survey_sections(id) ON DELETE SET NULL;


--
-- Name: survey_fields survey_fields_survey_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_fields
    ADD CONSTRAINT survey_fields_survey_id_foreign FOREIGN KEY (survey_id) REFERENCES public.surveys(id) ON DELETE CASCADE;


--
-- Name: survey_invitations survey_invitations_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_invitations
    ADD CONSTRAINT survey_invitations_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: survey_invitations survey_invitations_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_invitations
    ADD CONSTRAINT survey_invitations_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: survey_invitations survey_invitations_response_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_invitations
    ADD CONSTRAINT survey_invitations_response_id_foreign FOREIGN KEY (response_id) REFERENCES public.survey_responses(id) ON DELETE SET NULL;


--
-- Name: survey_invitations survey_invitations_survey_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_invitations
    ADD CONSTRAINT survey_invitations_survey_id_foreign FOREIGN KEY (survey_id) REFERENCES public.surveys(id) ON DELETE CASCADE;


--
-- Name: survey_invitations survey_invitations_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_invitations
    ADD CONSTRAINT survey_invitations_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: survey_responses survey_responses_invitation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_responses
    ADD CONSTRAINT survey_responses_invitation_id_foreign FOREIGN KEY (invitation_id) REFERENCES public.survey_invitations(id) ON DELETE SET NULL;


--
-- Name: survey_responses survey_responses_respondent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_responses
    ADD CONSTRAINT survey_responses_respondent_id_foreign FOREIGN KEY (respondent_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: survey_responses survey_responses_respondent_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_responses
    ADD CONSTRAINT survey_responses_respondent_organization_id_foreign FOREIGN KEY (respondent_organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: survey_responses survey_responses_reviewed_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_responses
    ADD CONSTRAINT survey_responses_reviewed_by_foreign FOREIGN KEY (reviewed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: survey_responses survey_responses_survey_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_responses
    ADD CONSTRAINT survey_responses_survey_id_foreign FOREIGN KEY (survey_id) REFERENCES public.surveys(id) ON DELETE CASCADE;


--
-- Name: survey_responses survey_responses_survey_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_responses
    ADD CONSTRAINT survey_responses_survey_version_id_foreign FOREIGN KEY (survey_version_id) REFERENCES public.survey_versions(id) ON DELETE SET NULL;


--
-- Name: survey_sections survey_sections_survey_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_sections
    ADD CONSTRAINT survey_sections_survey_id_foreign FOREIGN KEY (survey_id) REFERENCES public.surveys(id) ON DELETE CASCADE;


--
-- Name: survey_versions survey_versions_survey_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.survey_versions
    ADD CONSTRAINT survey_versions_survey_id_foreign FOREIGN KEY (survey_id) REFERENCES public.surveys(id) ON DELETE CASCADE;


--
-- Name: surveys surveys_canonical_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.surveys
    ADD CONSTRAINT surveys_canonical_id_foreign FOREIGN KEY (canonical_id) REFERENCES public.surveys(id) ON DELETE SET NULL;


--
-- Name: surveys surveys_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.surveys
    ADD CONSTRAINT surveys_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: surveys surveys_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.surveys
    ADD CONSTRAINT surveys_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: surveys surveys_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.surveys
    ADD CONSTRAINT surveys_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_assigned_to_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_assigned_to_foreign FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_milestone_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_milestone_id_foreign FOREIGN KEY (milestone_id) REFERENCES public.milestones(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_owner_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_owner_id_foreign FOREIGN KEY (owner_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.tasks(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_recurring_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_recurring_parent_id_foreign FOREIGN KEY (recurring_parent_id) REFERENCES public.tasks(id) ON DELETE SET NULL;


--
-- Name: users users_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: users users_department_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_department_id_foreign FOREIGN KEY (department_id) REFERENCES public.departments(id) ON DELETE SET NULL;


--
-- Name: users users_organization_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES public.organizations(id) ON DELETE SET NULL;


--
-- Name: users users_registration_approved_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_registration_approved_by_foreign FOREIGN KEY (registration_approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: users users_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict FpnGYWeaeZVEyIqJnPxEmI49rJbXeU2haWcGfJ0vtgxTAiE9PcaYUiJfEaGV1wX

--
-- PostgreSQL database dump
--

\restrict T8hF1nnzJgbK4G04DawN2jPX0Lb9AUdHcPJs5bXkGUzVSC7xwQmNR9UsHUkIBX4

-- Dumped from database version 16.14
-- Dumped by pg_dump version 18.4

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

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2024_01_01_000001_create_organizations_table	1
5	2024_01_01_000002_create_projects_table	1
6	2024_01_01_000003_create_milestones_table	1
7	2024_01_01_000004_create_tasks_table	1
8	2024_01_01_000005_create_stakeholders_table	1
9	2024_01_01_000006_create_project_members_table	1
10	2024_01_01_000007_create_comments_table	1
11	2024_01_01_000008_create_attachments_table	1
12	2024_01_01_000009_create_activity_logs_table	1
13	2025_12_28_091953_create_permission_tables	1
14	2025_12_28_103118_add_indexes_for_performance	1
15	2025_12_28_120406_add_extension_to_users_table	1
16	2025_12_28_120506_add_new_fields_to_projects_table	1
17	2025_12_28_120554_create_project_kpis_table	1
18	2025_12_28_120704_create_project_risks_table	1
19	2025_12_28_120754_add_fields_to_milestones_table	1
20	2025_12_28_200001_create_departments_table	1
21	2025_12_29_160611_add_department_id_to_users_table	1
22	2025_12_29_174611_add_created_by_to_users_table	1
23	2025_12_29_200000_add_performance_indexes	1
24	2025_12_30_014313_add_department_id_to_projects_table	1
25	2025_12_30_014746_create_milestone_deliverables_table	1
26	2025_12_30_132950_remove_summary_from_projects_table	1
27	2025_12_31_034056_create_personal_access_tokens_table	1
28	2025_12_31_100000_migrate_to_system_settings	1
29	2025_12_31_120000_fix_projects_priority_enum	1
30	2025_12_31_130000_remove_organization_id_from_projects	1
31	2026_01_05_025156_change_stakeholder_role_to_string	1
32	2026_01_06_030034_add_supervisor_and_settings_to_projects	1
33	2026_01_06_040000_create_project_expenses_table	1
34	2026_01_09_165724_fix_enum_values_consistency	1
35	2026_01_10_120000_add_sponsor_id_to_projects	1
36	2026_01_10_180000_add_user_id_to_stakeholders	1
37	2026_01_10_190000_update_stakeholder_roles	1
38	2026_01_11_170206_add_unified_task_fields_to_tasks_table	1
39	2026_01_12_000001_add_completion_fields_to_tasks_table	1
40	2026_01_12_100001_create_scoped_roles_tables	1
41	2026_01_12_100002_add_organization_support	1
42	2026_01_13_100001_create_dynamic_scoped_roles_tables	1
43	2026_01_13_200000_enhance_activity_logs_table	1
44	2026_01_13_200001_add_permission_fields_to_activity_logs	1
45	2026_01_15_000001_create_strategic_directions_table	1
46	2026_01_15_000002_create_strategic_objectives_table	1
47	2026_01_15_000003_create_initiatives_table	1
48	2026_01_15_000004_add_initiative_id_to_projects_table	1
49	2026_01_15_000005_create_strategic_kpis_table	1
50	2026_01_15_000006_create_strategic_kpi_measurements_table	1
51	2026_01_15_000007_create_blockers_table	1
52	2026_01_15_000008_create_decisions_table	1
53	2026_01_15_000009_create_reviews_table	1
54	2026_01_16_000001_add_directive_fields_to_strategic_directions	1
55	2026_01_16_100001_rename_strategic_directions_to_portfolios	1
56	2026_01_16_200001_convert_initiatives_to_programs	1
57	2026_01_16_200002_update_projects_for_programs	1
58	2026_01_16_200003_archive_and_drop_strategic_objectives	1
59	2026_01_16_200004_update_polymorphic_types	1
60	2026_01_17_153953_create_surveys_table	1
61	2026_01_17_153954_create_survey_versions_table	1
62	2026_01_17_153955_create_survey_sections_table	1
63	2026_01_17_153956_create_survey_fields_table	1
64	2026_01_17_153957_create_survey_invitations_table	1
65	2026_01_17_153958_create_survey_responses_table	1
66	2026_01_17_153959_create_survey_field_answers_table	1
67	2026_01_17_154000_create_survey_answer_files_table	1
68	2026_01_17_154001_create_data_mapping_templates_table	1
69	2026_01_17_154002_create_data_import_requests_table	1
70	2026_01_18_100001_make_tasks_project_id_nullable	1
71	2026_01_20_143244_add_performance_indexes	1
72	2026_01_20_152502_add_foreign_keys_to_projects_table	1
73	2026_01_20_152559_add_check_constraints_to_tasks_and_programs	1
74	2026_01_20_152642_set_default_organization_for_users	1
75	2026_01_20_160000_fix_postgresql_check_constraints	1
76	2026_01_21_182109_create_login_attempts_table	1
77	2026_01_21_182446_add_two_factor_columns_to_users_table	1
78	2026_02_09_100207_update_scoped_role_definitions_schema	1
79	2026_02_09_103711_add_preferred_locale_to_users_table	1
80	2026_02_20_000001_add_performance_indexes	1
81	2026_06_07_000001_create_ovr_incident_types_table	1
82	2026_06_07_000002_create_ovr_reportable_types_table	1
83	2026_06_07_000003_create_ovr_incident_reports_table	1
84	2026_06_07_000004_create_ovr_report_comments_table	1
85	2026_06_07_000005_create_ovr_status_history_table	1
86	2026_06_07_031800_add_soft_deletes_to_users_table	1
87	2026_06_07_031801_add_soft_deletes_to_departments_table	1
88	2026_06_07_031802_add_original_amount_to_project_expenses	1
89	2026_06_07_031803_fix_cascade_to_restrict_on_foreign_keys	1
90	2026_06_07_050000_add_soft_deletes_to_remaining_tables	1
91	2026_06_07_050001_add_answers_snapshot_to_survey_responses	1
92	2026_06_07_050002_add_finalized_columns_to_project_expenses	1
93	2026_06_07_050723_make_activity_logs_loggable_id_nullable	1
94	2026_06_07_051035_fix_tasks_status_check	1
95	2026_06_07_060000_change_activity_logs_loggable_id_to_string	1
96	2026_06_07_070000_add_sla_notified_at_to_ovr_incident_reports	1
97	2026_06_07_080000_create_notifications_table	1
98	2026_06_08_100000_add_organization_id_to_strategy_tables	1
99	2026_06_08_110000_add_organization_id_to_projects	1
100	2026_06_09_000000_add_organization_id_to_departments	1
101	2026_06_09_000001_create_risks_table	1
102	2026_06_09_000002_create_risk_assessments_table	1
103	2026_06_09_000003_create_risk_actions_table	1
104	2026_06_09_000004_create_risk_action_updates_table	1
105	2026_06_09_000005_create_risk_status_changes_table	1
106	2026_06_09_000006_create_risk_alerts_table	1
107	2026_06_10_000001_create_department_default_roles_tables	1
108	2026_06_10_120000_add_requires_reportable_type_to_ovr_incident_types	1
109	2026_06_14_000001_add_consequences_to_risks_table	1
110	2026_06_14_000001_migrate_project_members_to_scoped_roles	1
111	2026_06_15_000001_add_methodology_fields_to_projects_table	1
112	2026_06_15_000001_unify_project_roles_to_scoped	1
113	2026_06_15_000002_add_closure_fields_to_projects_table	1
114	2026_06_15_000002_create_employee_profiles_table	1
115	2026_06_15_180000_add_privacy_mode_to_surveys_table	1
116	2026_06_16_100000_create_kpis_table	1
117	2026_06_16_100001_create_kpi_measurements_table	1
118	2026_06_16_100002_create_kpi_links_table	1
119	2026_06_16_120000_hash_2fa_recovery_codes	1
120	2026_06_17_000001_create_risk_settings_tables	1
121	2026_06_17_000002_fix_risk_impact_types_to_categories	1
122	2026_06_17_100000_migrate_admin_to_department_scoped_permissions	1
123	2026_06_17_110000_remove_deprecated_system_roles	1
124	2026_06_17_120000_remove_admin_viewer_system_roles	1
125	2026_06_18_000001_create_employee_personal_info_table	1
126	2026_06_18_000002_create_employee_certificates_table	1
127	2026_06_18_000003_expand_employee_profiles_table	1
128	2026_06_18_000004_replace_first_last_name_with_full_name_english_in_personal_info	1
129	2026_06_18_000005_drop_manager_id_from_employee_profiles	1
130	2026_06_18_120000_backfill_default_organization	1
131	2026_06_19_000001_create_meetings_table	1
132	2026_06_19_000001_migrate_project_kpis_to_performance	1
133	2026_06_19_000002_create_meeting_attendees_table	1
134	2026_06_19_000002_migrate_strategic_kpis_to_performance	1
135	2026_06_19_000003_add_meeting_id_to_decisions_table	1
136	2026_06_19_000003_drop_legacy_kpi_tables	1
137	2026_06_19_000004_create_recommendations_table	1
138	2026_06_19_100001_add_reference_number_to_decisions	1
139	2026_06_19_100002_backfill_reference_numbers	1
140	2026_06_19_100003_add_unique_index_to_reference_numbers	1
141	2026_06_19_200721_add_meetings_permissions	1
142	2026_06_20_000001_create_meeting_agenda_items_table	1
143	2026_06_20_000002_create_meeting_categories_table	1
144	2026_06_20_000003_create_meeting_settings_table	1
145	2026_06_20_100001_backfill_scope_types_and_role_definitions	1
146	2026_06_20_100002_backfill_functional_roles_to_scoped_org	1
147	2026_06_20_100003_backfill_strategy_fk_to_scoped_roles	1
148	2026_06_20_100004_unify_scoped_role_definitions_schema	1
149	2026_06_20_200001_add_organization_id_to_portfolios	1
150	2026_06_20_200002_add_can_view_confidential_to_scoped_role_definitions	1
151	2026_06_20_300001_drop_strategy_role_fk_columns	1
152	2026_06_21_000001_fix_stakeholders_user_id_bigint	1
153	2026_06_21_100001_backfill_project_scope_type_and_role_definitions	1
154	2026_06_22_000001_add_source_to_model_has_scoped_roles	1
155	2026_06_22_000002_create_department_capacity_roles_table	1
156	2026_06_22_100001_add_registration_status_to_users_table	1
157	2026_06_22_100002_create_employee_roster_entries_table	1
158	2026_06_22_100003_create_email_otps_table	1
159	2026_06_22_100004_add_lowercase_email_index_to_employee_roster_entries	1
160	2026_06_22_200001_backfill_unified_task_polymorphic_types	1
161	2026_06_27_120000_add_organization_id_to_ovr_types	1
162	2026_06_27_121000_widen_patient_file_number_to_text	1
163	2026_06_27_130000_add_gin_index_to_contributing_factors	1
164	2026_06_28_031330_drop_legacy_authz_strings	1
165	2026_06_28_060831_drop_view_ladder_grants	1
166	2026_06_28_061917_drop_surveys_legacy_grants	1
167	2026_06_29_000001_consolidate_tasks_status_check	1
168	2026_06_29_000002_lock_activity_logs_loggable_id_varchar	1
169	2026_06_30_000001_backfill_legacy_department_roles_to_scoped	1
170	2026_06_30_000002_drop_legacy_department_role_tables	1
171	2026_07_01_000001_create_governance_rules_table	1
172	2026_07_01_000002_migrate_governing_departments_to_rules	1
173	2026_07_01_100001_backfill_granular_flags_into_permissions	1
174	2026_07_01_100002_drop_granular_flags_from_scoped_role_definitions	1
175	2026_07_01_110001_add_reach_to_scoped_role_definitions	1
176	2026_07_03_000001_create_authorization_roles	1
177	2026_07_03_000002_create_authorization_resources	1
178	2026_07_03_000003_create_authorization_role_assignments	1
179	2026_07_03_000004_create_authorization_role_permissions	1
180	2026_07_03_000005_create_authorization_record_rules	1
181	2026_07_03_000006_create_authorization_decision_audits	1
182	2026_07_03_000010_backfill_authorization_role_permissions	1
183	2026_07_04_000020_relax_authorization_role_assignments_scope_check	1
184	2026_07_04_000021_backfill_scoped_roles_full_semantics	1
185	2026_07_04_000022_add_inherit_to_children_to_authorization_role_assignments	1
186	2026_07_05_000001_add_department_id_to_kpis_table	1
187	2026_07_05_000002_add_department_id_to_meetings_table	1
188	2026_07_05_000003_add_department_id_to_surveys_table	1
189	2026_07_05_000023_add_reach_to_authorization_role_permissions	1
190	2026_07_05_000024_backfill_authorization_role_permissions_reach	1
191	2026_07_05_000025_add_is_admin_role_to_authorization_roles	1
192	2026_07_05_000026_backfill_authorization_roles_is_admin_role	1
193	2026_07_05_000027_backfill_authorization_role_permissions_ovr_confidential	1
194	2026_07_05_171421_add_source_fields_to_tasks_table	1
195	2026_07_06_000010_drop_employee_roster_entries_table	1
196	2026_07_06_300001_drop_decision_id_from_recommendations	1
197	2026_07_06_300002_add_ruling_fields_to_recommendations	1
198	2026_07_06_300003_drop_decisions_table	1
199	2026_07_06_300004_extend_recommendations_status_check	1
200	2026_07_06_300005_add_defer_metadata_to_recommendations	1
201	2026_07_06_300010_add_soft_deletes_and_indexes_to_project_risks	1
202	2026_07_07_000001_create_meeting_resolutions_table	1
203	2026_07_07_000002_create_resolution_links_table	1
204	2026_07_07_000003_add_deleted_at_to_meeting_resolutions	1
205	2026_07_07_000005_add_tracking_token_to_incident_reports	1
206	2026_07_07_000010_strip_legacy_ovr_view_confidential	1
207	2026_07_07_100001_add_organization_id_to_activity_logs	1
208	2026_07_10_000001_index_departments_path	1
209	2026_07_10_120000_add_respondent_organization_snapshot_to_survey_responses	1
210	2026_07_11_000001_create_ovr_settings_table	1
211	2026_07_11_000001_create_risk_settings_table	1
212	2026_07_11_000001_fix_projects_organization_id_from_department	1
213	2026_07_11_000002_create_ovr_incident_participants_table	1
214	2026_07_11_000002_rename_project_type_new_to_development	1
215	2026_07_11_100000_add_org_scope_indexes_to_projects_tasks_risks	1
216	2026_07_11_100000_provision_cluster_auditor_role	1
217	2026_07_12_000001_seed_engine_capabilities_dashboard_data_imports	1
218	2026_07_12_000002_seed_engine_capability_view_survey_responses	1
219	2026_07_12_000003_add_hierarchy_to_organizations_table	1
220	2026_07_12_000004_widen_encrypted_survey_response_name	1
221	2026_07_12_000005_add_lifecycle_to_authorization_roles_and_assignments	1
222	2026_07_12_000006_backfill_authorization_lifecycle	1
223	2026_07_12_000007_add_provenance_to_authorization_role_assignments	1
224	2026_07_12_000008_add_metadata_to_authorization_roles	1
225	2026_07_12_000009_reconcile_legacy_authorization_assignments	1
226	2026_07_12_000010_rename_permission_audits_to_authorization_assignment_audits	1
227	2026_07_12_000011_drop_legacy_authorization_tables	1
228	2026_07_12_000012_restrict_authorization_assignment_scopes	2
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 228, true);


--
-- PostgreSQL database dump complete
--

\unrestrict T8hF1nnzJgbK4G04DawN2jPX0Lb9AUdHcPJs5bXkGUzVSC7xwQmNR9UsHUkIBX4
