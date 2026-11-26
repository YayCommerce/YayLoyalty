import { describe, expect, test } from "vitest";

import { bulkLiabilityProjection } from "@/pages/customers/components/customers-adjust-points.form-state";

describe("bulkLiabilityProjection", () => {
  test("adding to balances all at or above zero shows the exact before and after", () => {
    expect(bulkLiabilityProjection("add", 400, 0, false)).toEqual({
      kind: "range",
      from: 0,
      to: 400,
    });
  });

  test("adding while a selected balance is below zero shows only an upper bound", () => {
    // Customers at -500 and 0, 200 each: liability goes 0 → 200, not 0 → 400.
    expect(bulkLiabilityProjection("add", 400, 0, true)).toEqual({
      kind: "delta",
      delta: 400,
    });
  });

  test("removing is always an upper bound", () => {
    expect(bulkLiabilityProjection("remove", 400, 1000, false)).toEqual({
      kind: "delta",
      delta: 400,
    });
  });

  test("nothing to show for a zero total", () => {
    expect(bulkLiabilityProjection("add", 0, 1000, false)).toEqual({
      kind: "none",
    });
  });
});
