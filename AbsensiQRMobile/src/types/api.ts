/**
 * API Response Types
 *
 * Standard TypeScript interfaces for API responses.
 * Matches the backend ResponseFormatter format.
 *
 * @version 1.0.0
 */

/**
 * Standard API Response
 */
export interface ApiResponse<T = any> {
    success: boolean;
    code: number;
    message: string;
    data?: T;
    meta?: ApiMeta;
    errors?: ValidationErrors;
}

/**
 * API Metadata
 */
export interface ApiMeta {
    pagination?: PaginationMeta;
    retry_after?: number;
    timestamp?: string;
    [key: string]: any;
}

/**
 * Pagination Metadata
 */
export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

/**
 * Validation Errors
 */
export interface ValidationErrors {
    [field: string]: string[];
}

/**
 * Paginated Response
 */
export interface PaginatedResponse<T> extends ApiResponse<T[]> {
    meta: {
        pagination: PaginationMeta;
    };
}

/**
 * API Error
 */
export class ApiError extends Error {
    public code: number;
    public errors?: ValidationErrors;

    constructor(message: string, code: number, errors?: ValidationErrors) {
        super(message);
        this.name = 'ApiError';
        this.code = code;
        this.errors = errors;
    }
}

/**
 * Type guard for API Response
 */
export function isApiResponse<T = any>(obj: any): obj is ApiResponse<T> {
    return (
        typeof obj === 'object' &&
        obj !== null &&
        'success' in obj &&
        'code' in obj &&
        'message' in obj
    );
}

/**
 * Type guard for Paginated Response
 */
export function isPaginatedResponse<T = any>(
    obj: any
): obj is PaginatedResponse<T> {
    return (
        isApiResponse(obj) &&
        obj.meta !== undefined &&
        'pagination' in obj.meta
    );
}

/**
 * Extract data from API response
 */
export function extractData<T>(response: ApiResponse<T>): T {
    if (!response.success) {
        throw new ApiError(response.message, response.code, response.errors);
    }
    return response.data as T;
}

/**
 * Extract paginated data
 */
export function extractPaginatedData<T>(
    response: PaginatedResponse<T>
): { data: T[]; pagination: PaginationMeta } {
    if (!response.success) {
        throw new ApiError(response.message, response.code, response.errors);
    }
    return {
        data: response.data || [],
        pagination: response.meta.pagination,
    };
}
