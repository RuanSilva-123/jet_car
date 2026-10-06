/**
 * A API é servida pelo mesmo domínio do painel (Nginx encaminha /api ao Laravel),
 * por isso as URLs são relativas — requisito para o cookie de sessão e o XSRF funcionarem.
 */
export const API_URL = '/api/v1';
export const CSRF_COOKIE_URL = '/sanctum/csrf-cookie';

/** Envelope padrão das API Resources do Laravel. */
export interface ApiResource<T> {
  data: T;
}

/** Coleção paginada (Resource::collection($query->paginate())). */
export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}

/** Corpo de um erro 422 do Laravel. */
export interface ValidationErrorBody {
  message: string;
  errors: Record<string, string[]>;
}
